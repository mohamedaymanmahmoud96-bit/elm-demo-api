<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssignmentController extends Controller
{
    public function index(Course $course)
    {
        return $course->assignments()->withCount('submissions')->latest()->get();
    }

    public function store(Request $request, Course $course)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher', 'assistant'], true), 403);
        abort_unless($request->user()->managesTeacher($course->teacher_id), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.type' => ['required', 'in:mcq,essay,true_false'],
            'questions.*.text' => ['required', 'string', 'max:1000'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*' => ['string', 'max:255'],
            'questions.*.correct_answer' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless(
            $course->lessons()->whereKey($data['lesson_id'])->exists(),
            422,
            'الحصة لا تنتمي لهذا المعلّm.'
        );
        abort_if(
            Assignment::where('lesson_id', $data['lesson_id'])->exists(),
            422,
            'يوجد واجب بالفعل لهذه الحصة.'
        );

        $normalized = [];
        foreach ($data['questions'] as $index => $question) {
            $type = $question['type'];
            $correct = trim((string) ($question['correct_answer'] ?? ''));
            if ($correct === '') {
                abort(422, 'السؤال '.($index + 1).': أدخل الإجابة الصحيحة.');
            }
            $normalized[] = [
                'type' => $type,
                'text' => $question['text'],
                'options' => $type === 'mcq' ? array_values($question['options'] ?? []) : [],
                'correct_answer' => $correct,
            ];
        }
        $data['questions'] = $normalized;
        $data['course_id'] = $course->id;
        $data['teacher_id'] = $request->user()->owningTeacherId() ?? $course->teacher_id;

        return response()->json(Assignment::create($data), 201);
    }

    public function forLesson(Request $request, Lesson $lesson)
    {
        $lesson->loadMissing('course');
        $this->ensureLessonAccess($request, $lesson);

        $studentId = $request->user()->role === 'student' ? $request->user()->id : null;

        $assignment = Assignment::query()
            ->where('lesson_id', $lesson->id)
            ->when(
                $studentId !== null,
                fn ($q) => $q->with(['submissions' => fn ($sub) => $sub->where('student_id', $studentId)]),
                fn ($q) => $q->with('submissions')
            )
            ->first();

        if ($assignment && $request->user()->role === 'student') {
            foreach ($assignment->submissions as $submission) {
                $this->enrichStudentSubmission($assignment, $submission);
            }
            $questions = collect($assignment->questions ?? [])->map(function ($q) {
                if (! is_array($q)) {
                    return ['type' => 'essay', 'text' => (string) $q, 'options' => []];
                }
                unset($q['correct_answer']);

                return $q;
            })->values()->all();
            $assignment->questions = $questions;
        }

        return $assignment;
    }

    protected function ensureLessonAccess(Request $request, Lesson $lesson): void
    {
        if ($request->user()->role === 'student') {
            abort_unless(
                \App\Models\Enrollment::query()
                    ->where('student_id', $request->user()->id)
                    ->where('course_id', $lesson->course_id)
                    ->where('status', 'active')
                    ->exists(),
                403
            );

            return;
        }
        abort_unless($request->user()->managesTeacher($lesson->course->teacher_id), 403);
    }

    public function submissions(Request $request, Assignment $assignment)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher', 'assistant'], true), 403);
        abort_unless($request->user()->managesTeacher($assignment->teacher_id), 403);

        return $assignment->submissions()
            ->with('student:id,name,access_code')
            ->latest('submitted_at')
            ->get();
    }

    public function submit(Request $request, Assignment $assignment)
    {
        abort_unless($request->user()->role === 'student', 403);
        abort_if(
            AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_id', $request->user()->id)
                ->whereNotNull('submitted_at')
                ->exists(),
            422,
            'تم تسليم هذا الواجب مسبقاً — لا يمكن الدخول أو التعديل مرة أخرى.'
        );
        $data = $request->validate([
            'answer' => ['nullable', 'string'],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ]);

        $answers = $data['answers'] ?? [];
        if ($answers === [] && ! empty($data['answer'])) {
            $answers = ['0' => $data['answer']];
        }

        $autoReview = $this->buildAutoReview($assignment, $answers);
        $feedback = $autoReview['summary'] ?? null;

        $submission = AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $request->user()->id],
            [
                'answer' => $data['answer'] ?? json_encode($answers, JSON_UNESCAPED_UNICODE),
                'answers' => $answers,
                'attachment_path' => $data['attachment_path'] ?? null,
                'auto_review' => $autoReview,
                'feedback' => $feedback,
                'score' => null,
                'submitted_at' => now(),
            ]
        );

        $submission->refresh();
        $this->enrichStudentSubmission($assignment, $submission);

        return response()->json($submission, 201);
    }

    public function grade(Request $request, AssignmentSubmission $submission)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher', 'assistant'], true), 403);
        abort_unless($request->user()->managesTeacher($submission->assignment->teacher_id), 403);
        $data = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string'],
        ]);
        $submission->update($data);

        return $submission->fresh();
    }

    private function buildAutoReview(Assignment $assignment, array $answers): array
    {
        $questions = $assignment->questions ?? [];
        $items = [];
        $autoGraded = 0;
        $correctCount = 0;

        foreach ($questions as $index => $question) {
            if (is_string($question)) {
                $question = ['type' => 'essay', 'text' => $question, 'correct_answer' => null];
            }
            $type = $question['type'] ?? 'essay';
            $studentAnswer = trim((string) ($answers[$index] ?? $answers[(string) $index] ?? ''));
            $item = [
                'index' => $index,
                'type' => $type,
                'student_answer' => $studentAnswer,
                'status' => 'pending_teacher',
                'message' => 'بانتظار مراجعة المعلّm',
            ];

            if (in_array($type, ['mcq', 'true_false'], true)) {
                $autoGraded++;
                $expected = trim((string) ($question['correct_answer'] ?? ''));
                $isCorrect = $this->answersMatch($studentAnswer, $expected, $type);
                if ($isCorrect) {
                    $correctCount++;
                }
                $item['status'] = $isCorrect ? 'correct' : 'incorrect';
                $item['message'] = $isCorrect ? 'إجابة صحيحة' : 'إجابة غير صحيحة';
                $item['expected'] = $expected;
            } elseif ($type === 'essay') {
                $autoGraded++;
                $expected = trim((string) ($question['correct_answer'] ?? ''));
                if ($expected !== '') {
                    $isCorrect = trim($studentAnswer) === $expected;
                    if ($isCorrect) {
                        $correctCount++;
                    }
                    $item['status'] = $isCorrect ? 'correct' : 'incorrect';
                    $item['message'] = $isCorrect ? 'إجابة صحيحة (مطابقة للنموذج)' : 'إجابة غير مطابقة للنموذج';
                    $item['expected'] = $expected;
                } else {
                    $item['message'] = 'مقالي — بانتظار تصحيح المعلّm';
                }
            }

            $items[] = $item;
        }

        $summaryParts = [];
        if ($autoGraded > 0) {
            $summaryParts[] = "تصحيح فوري: {$correctCount} من {$autoGraded}";
        }

        return [
            'items' => $items,
            'summary' => implode(' — ', $summaryParts),
        ];
    }

    private function answersMatch(string $student, string $expected, string $type): bool
    {
        $normalize = fn (string $v) => Str::lower(trim($v));
        $s = $normalize($student);
        $e = $normalize($expected);
        if ($s === $e) {
            return true;
        }
        if ($type === 'true_false') {
            $trueValues = ['true', '1', 'صح', 'صحيح', 'نعم'];
            $falseValues = ['false', '0', 'خطأ', 'غلط', 'لا'];
            $sBool = in_array($s, $trueValues, true) ? 'true' : (in_array($s, $falseValues, true) ? 'false' : $s);
            $eBool = in_array($e, $trueValues, true) ? 'true' : (in_array($e, $falseValues, true) ? 'false' : $e);

            return $sBool === $eBool;
        }

        return false;
    }

    /**
     * يكمّل الإجابات والتصحيح للعرض (سجلات قديمة أو answers فارغة).
     */
    protected function enrichStudentSubmission(Assignment $assignment, AssignmentSubmission $submission): void
    {
        $questions = $assignment->questions ?? [];
        $answers = $submission->answers;
        if (! is_array($answers) || $answers === []) {
            $raw = $submission->answer;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $answers = $decoded;
                    $submission->setAttribute('answers', $answers);
                }
            }
        }

        $review = $submission->auto_review;
        if (! is_array($review)) {
            $review = [];
        }
        $items = $review['items'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }
            $idx = $item['index'] ?? $key;
            if (trim((string) ($item['student_answer'] ?? '')) === '' && is_array($answers)) {
                $item['student_answer'] = trim((string) ($answers[$idx] ?? $answers[(string) $idx] ?? ''));
            }
            $question = $questions[$idx] ?? null;
            if (is_string($question)) {
                $question = ['type' => 'essay', 'text' => $question, 'correct_answer' => ''];
            }
            if (is_array($question) && trim((string) ($item['expected'] ?? '')) === '') {
                $expected = trim((string) ($question['correct_answer'] ?? ''));
                if ($expected !== '') {
                    $item['expected'] = $expected;
                }
            }
            $items[$key] = $item;
        }

        $submission->setAttribute('auto_review', array_merge($review, ['items' => array_values($items)]));
    }
}
