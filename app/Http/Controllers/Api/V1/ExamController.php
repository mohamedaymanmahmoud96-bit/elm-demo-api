<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ChecksStudentCourseAccess;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    use ChecksStudentCourseAccess;

    public function index(Request $request, Course $course)
    {
        $this->ensureStudentCanAccessCourse($request, $course->id);

        return $course->exams()->withCount('questions')->latest()->get();
    }

    public function show(Request $request, Exam $exam)
    {
        $this->ensureStudentCanAccessCourse($request, (int) $exam->course_id);

        $exam->load(['course:id,title', 'teacher:id,name', 'questions' => fn ($q) => $q->orderBy('sort_order')]);

        if ($request->user()?->role === 'student') {
            $exam->questions->each(fn ($question) => $question->makeHidden(['correct_answer']));
        }

        return $exam;
    }

    public function store(Request $request, Course $course)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher']), 403);
        abort_unless($request->user()->role === 'admin' || $course->teacher_id === $request->user()->id, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'total_points' => ['nullable', 'numeric', 'min:1'],
            'exam_mode' => ['nullable', 'in:mixed,mcq,essay'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'published_at' => ['nullable', 'date'],
        ]);
        $data['course_id'] = $course->id;
        $data['teacher_id'] = $request->user()->role === 'teacher' ? $request->user()->id : $course->teacher_id;
        $data['total_points'] = $data['total_points'] ?? 100;
        $data['exam_mode'] = $data['exam_mode'] ?? 'mixed';
        $data['max_attempts'] = $data['max_attempts'] ?? 1;

        return response()->json(Exam::create($data), 201);
    }

    public function addQuestion(Request $request, Exam $exam)
    {
        abort_unless($request->user()->role === 'admin' || $exam->teacher_id === $request->user()->id, 403);
        $data = $request->validate([
            'question' => ['required', 'string'],
            'type' => ['required', 'in:multiple_choice,true_false,short_answer'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'correct_answer' => ['nullable', 'string'],
            'points' => ['sometimes', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($data['type'] === 'true_false') {
            $data['options'] = ['صح', 'خطأ'];
            abort_if(empty($data['correct_answer']), 422, 'حدد الإجابة الصحيحة (صح أو خطأ).');
        }

        if ($data['type'] === 'multiple_choice') {
            abort_if(empty($data['options']) || count($data['options']) < 2, 422, 'أضف خيارين على الأقل.');
            abort_if(empty($data['correct_answer']), 422, 'حدد الإجابة الصحيحة.');
        }

        return response()->json($exam->questions()->create($data), 201);
    }

    public function submit(Request $request, Exam $exam)
    {
        abort_unless($request->user()->role === 'student', 403);
        $this->ensureStudentCanAccessCourse($request, (int) $exam->course_id);
        $data = $request->validate([
            'answers' => ['required', 'array'],
            'started_at' => ['nullable', 'date'],
        ]);
        $exam->load(['questions', 'teacher:id,name']);

        $attemptsUsed = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $request->user()->id)
            ->count();
        abort_if($attemptsUsed >= (int) ($exam->max_attempts ?? 1), 422, 'استنفدت عدد محاولات هذا الامتحان.');

        if ($exam->duration_minutes && !empty($data['started_at'])) {
            $started = \Carbon\Carbon::parse($data['started_at']);
            abort_if($started->diffInMinutes(now()) > (int) $exam->duration_minutes, 422, 'انتهى وقت الامتحان.');
        }

        [$score, $review] = $this->gradeAnswers($exam, $data['answers']);

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => $request->user()->id,
            'answers' => $data['answers'],
            'score' => $score,
            'started_at' => !empty($data['started_at']) ? $data['started_at'] : now(),
            'submitted_at' => now(),
        ]);

        return response()->json([
            'attempt' => $attempt,
            'review' => $review,
            'total_points' => (float) ($exam->total_points ?? 100),
            'teacher_id' => $exam->teacher_id,
            'teacher_name' => $exam->teacher?->name,
            'exam_title' => $exam->title,
            'grading' => 'automatic',
        ], 201);
    }

    public function myLatestResult(Request $request, Exam $exam)
    {
        abort_unless($request->user()->role === 'student', 403);
        $this->ensureStudentCanAccessCourse($request, (int) $exam->course_id);

        $attempt = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $request->user()->id)
            ->latest('submitted_at')
            ->first();

        abort_unless($attempt, 404, 'لم تسلّم هذا الامتحان بعد.');

        $exam->load(['questions', 'teacher:id,name']);
        $answers = is_array($attempt->answers) ? $attempt->answers : [];
        [, $review] = $this->gradeAnswers($exam, $answers);

        $attemptsUsed = ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $request->user()->id)
            ->count();

        return response()->json([
            'attempt' => $attempt,
            'review' => $review,
            'total_points' => (float) ($exam->total_points ?? 100),
            'teacher_id' => $exam->teacher_id,
            'teacher_name' => $exam->teacher?->name,
            'exam_title' => $exam->title,
            'grading' => 'automatic',
            'attempts_used' => $attemptsUsed,
            'max_attempts' => (int) ($exam->max_attempts ?? 1),
            'can_retake' => $attemptsUsed < (int) ($exam->max_attempts ?? 1),
        ]);
    }

    /**
     * @return array{0: float, 1: array<int, array<string, mixed>>}
     */
    private function gradeAnswers(Exam $exam, array $answers): array
    {
        $score = 0.0;
        $review = [];

        foreach ($exam->questions as $question) {
            $answer = $answers[$question->id]
                ?? $answers[(string) $question->id]
                ?? null;
            $studentAnswer = trim((string) ($answer ?? ''));
            $correctAnswer = trim((string) ($question->correct_answer ?? ''));
            $isCorrect = false;

            if (in_array($question->type, ['multiple_choice', 'true_false'], true)) {
                $isCorrect = $studentAnswer !== '' && $studentAnswer === $correctAnswer;
            } else {
                $isCorrect = $studentAnswer !== '' && mb_strtolower($studentAnswer) === mb_strtolower($correctAnswer);
            }

            $earned = $isCorrect ? (float) $question->points : 0.0;
            $score += $earned;

            $review[] = [
                'question_id' => $question->id,
                'question' => $question->question,
                'type' => $question->type,
                'options' => $question->options,
                'student_answer' => $studentAnswer,
                'correct_answer' => $correctAnswer,
                'is_correct' => $isCorrect,
                'points_earned' => $earned,
                'points_total' => (float) $question->points,
            ];
        }

        return [$score, $review];
    }

    public function myAttempts(Request $request)
    {
        abort_unless($request->user()->role === 'student', 403);

        return ExamAttempt::query()
            ->with(['exam:id,title,course_id,total_points', 'exam.course:id,title'])
            ->where('student_id', $request->user()->id)
            ->latest('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $attempt) => [
                'id' => $attempt->id,
                'exam_id' => $attempt->exam_id,
                'exam_title' => $attempt->exam?->title,
                'course_title' => $attempt->exam?->course?->title,
                'score' => (float) $attempt->score,
                'total_points' => (float) ($attempt->exam?->total_points ?? 100),
                'submitted_at' => $attempt->submitted_at,
            ]);
    }

    public function teacherAttempts(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'assistant'], true), 403);

        $teacherId = $request->user()->resolvedTeacherId();
        $courseIds = Course::query()
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->pluck('id');

        return ExamAttempt::query()
            ->with([
                'student:id,name,access_code,grade',
                'exam:id,title,course_id,total_points',
                'exam.course:id,title',
            ])
            ->whereIn('exam_id', function ($sub) use ($courseIds) {
                $sub->select('id')->from('exams')->whereIn('course_id', $courseIds);
            })
            ->latest('submitted_at')
            ->get()
            ->map(fn (ExamAttempt $attempt) => [
                'id' => $attempt->id,
                'student_id' => $attempt->student_id,
                'student_name' => $attempt->student?->name,
                'student_code' => $attempt->student?->access_code,
                'student_grade' => $attempt->student?->grade,
                'exam_id' => $attempt->exam_id,
                'exam_title' => $attempt->exam?->title,
                'course_title' => $attempt->exam?->course?->title,
                'score' => (float) $attempt->score,
                'total_points' => (float) ($attempt->exam?->total_points ?? 100),
                'submitted_at' => $attempt->submitted_at,
            ]);
    }
}
