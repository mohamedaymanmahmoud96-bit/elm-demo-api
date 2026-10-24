<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonComplianceRecord;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    public function myStats(Request $request)
    {
        abort_unless($request->user()->role === 'student', 403);
        $studentId = $request->user()->id;

        $lessonsCompleted = LessonProgress::query()
            ->where('student_id', $studentId)
            ->whereNotNull('completed_at')
            ->count();

        $lessonsStarted = LessonProgress::query()
            ->where('student_id', $studentId)
            ->count();

        $examAttempts = ExamAttempt::query()->where('student_id', $studentId)->count();
        $examPassed = ExamAttempt::query()
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->where('exam_attempts.student_id', $studentId)
            ->whereRaw('exam_attempts.score >= exams.total_points * 0.5')
            ->count();

        $assignmentsSubmitted = AssignmentSubmission::query()
            ->where('student_id', $studentId)
            ->whereNotNull('submitted_at')
            ->count();

        $days = 7;
        $labels = [];
        $lessonsSeries = [];
        $examsSeries = [];
        $assignmentsSeries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $labels[] = $day->format('d/m');
            $lessonsSeries[] = LessonProgress::query()
                ->where('student_id', $studentId)
                ->whereNotNull('completed_at')
                ->whereDate('completed_at', $day)
                ->count();
            $examsSeries[] = ExamAttempt::query()
                ->where('student_id', $studentId)
                ->whereDate('submitted_at', $day)
                ->count();
            $assignmentsSeries[] = AssignmentSubmission::query()
                ->where('student_id', $studentId)
                ->whereNotNull('submitted_at')
                ->whereDate('submitted_at', $day)
                ->count();
        }

        $watchPct = $lessonsStarted > 0 ? round(($lessonsCompleted / $lessonsStarted) * 100, 1) : 0.0;

        return response()->json([
            'summary' => [
                'lessons_completed' => $lessonsCompleted,
                'lessons_started' => $lessonsStarted,
                'watching_percentage' => $watchPct,
                'exam_attempts' => $examAttempts,
                'exam_passed' => $examPassed,
                'assignments_submitted' => $assignmentsSubmitted,
            ],
            'charts' => [
                'activity_last_7_days' => [
                    'labels' => $labels,
                    'lessons_completed' => $lessonsSeries,
                    'exam_attempts' => $examsSeries,
                    'assignments_submitted' => $assignmentsSeries,
                ],
            ],
        ]);
    }

    public function updateLesson(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->role === 'student', 403);
        $data = $request->validate([
            'watched_seconds' => ['required', 'integer', 'min:0'],
            'last_position_seconds' => ['sometimes', 'integer', 'min:0'],
            'completed' => ['sometimes', 'boolean'],
        ]);
        $completed = (bool) ($data['completed'] ?? false);
        $progress = LessonProgress::updateOrCreate(
            ['student_id' => $request->user()->id, 'lesson_id' => $lesson->id],
            [
                'watched_seconds' => $data['watched_seconds'],
                'last_position_seconds' => $data['last_position_seconds'] ?? $data['watched_seconds'],
                'started_at' => now(),
                'completed_at' => $completed ? now() : null,
            ]
        );

        return response()->json($progress->load('lesson:id,title,course_id'));
    }

    public function studentReport(Request $request, User $student)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher', 'assistant']), 403);
        abort_unless($student->role === 'student', 404);

        $teacherId = $request->user()->resolvedTeacherId();
        if ($teacherId) {
            abort_unless(DB::table('courses')
                ->join('enrollments', 'enrollments.course_id', '=', 'courses.id')
                ->where('courses.teacher_id', $teacherId)
                ->where('enrollments.student_id', $student->id)
                ->exists(), 403);
        }

        $from = $request->date('from');
        $to = $request->date('to');

        $watching = DB::table('lesson_progress')
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->join('courses', 'courses.id', '=', 'lessons.course_id')
            ->where('lesson_progress.student_id', $student->id)
            ->when($teacherId, fn ($q) => $q->where('courses.teacher_id', $teacherId))
            ->when($from, fn ($q) => $q->whereDate('lesson_progress.updated_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('lesson_progress.updated_at', '<=', $to))
            ->select([
                'courses.id as course_id', 'courses.title as course_title',
                'lessons.id as lesson_id', 'lessons.title as lesson_title',
                'lesson_progress.watched_seconds', 'lesson_progress.last_position_seconds',
                'lesson_progress.completed_at',
            ])
            ->orderBy('courses.title')
            ->orderBy('lessons.sort_order')
            ->get();

        $examsQuery = DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('courses', 'courses.id', '=', 'exams.course_id')
            ->where('exam_attempts.student_id', $student->id);

        if ($teacherId) {
            $examsQuery->where('courses.teacher_id', $teacherId);
        }

        $exams = $examsQuery
            ->when($from, fn ($q) => $q->whereDate('exam_attempts.submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('exam_attempts.submitted_at', '<=', $to))
            ->select(
                'courses.title as course_title',
                'exams.title as exam_title',
                'exam_attempts.score',
                'exams.total_points',
                'exam_attempts.submitted_at'
            )
            ->latest('exam_attempts.submitted_at')
            ->get()
            ->map(fn ($row) => $this->mapExamReportRow($row));

        $assignments = DB::table('assignment_submissions')
            ->join('assignments', 'assignments.id', '=', 'assignment_submissions.assignment_id')
            ->join('courses', 'courses.id', '=', 'assignments.course_id')
            ->where('assignment_submissions.student_id', $student->id)
            ->when($teacherId, fn ($q) => $q->where('courses.teacher_id', $teacherId))
            ->when($from, fn ($q) => $q->whereDate('assignment_submissions.submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('assignment_submissions.submitted_at', '<=', $to))
            ->select(
                'courses.title as course_title',
                'assignments.title as assignment_title',
                'assignment_submissions.score',
                'assignment_submissions.feedback',
                'assignment_submissions.auto_review',
                'assignment_submissions.submitted_at'
            )
            ->latest('assignment_submissions.submitted_at')
            ->get()
            ->map(fn ($row) => $this->mapAssignmentReportRow($row));

        $absences = LessonComplianceRecord::query()
            ->where('student_id', $student->id)
            ->when($teacherId, fn ($q) => $q->whereIn(
                'course_id',
                Course::query()->where('teacher_id', $teacherId)->pluck('id')
            ))
            ->when($from, fn ($q) => $q->whereDate('recorded_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('recorded_at', '<=', $to))
            ->with(['missedLesson:id,title', 'student:id,name'])
            ->latest('recorded_at')
            ->get()
            ->map(fn (LessonComplianceRecord $row) => [
                'course_id' => $row->course_id,
                'lesson_id' => $row->missed_lesson_id,
                'lesson_title' => $row->missedLesson?->title,
                'kind' => $row->kind,
                'recorded_at' => $row->recorded_at,
            ]);

        $completedLessons = $watching->whereNotNull('completed_at')->count();
        $examNumeric = $exams->pluck('score')->filter(fn ($v) => $v !== null);
        $averageExamScore = $examNumeric->isEmpty() ? 0 : round((float) $examNumeric->avg(), 2);
        $assignmentNumeric = $assignments->pluck('numeric_score')->filter(fn ($v) => $v !== null);
        $averageAssignmentScore = $assignmentNumeric->isEmpty() ? 0 : round((float) $assignmentNumeric->avg(), 2);

        return response()->json([
            'student' => $student->only(['id', 'name', 'email', 'phone', 'access_code', 'grade']),
            'summary' => [
                'lessons_total' => $watching->count(),
                'lessons_completed' => $completedLessons,
                'watching_percentage' => $watching->count() > 0
                    ? round(($completedLessons / $watching->count()) * 100, 1)
                    : 0,
                'average_exam_score' => $averageExamScore,
                'average_assignment_score' => $averageAssignmentScore,
                'assignment_auto_average_percent' => $averageAssignmentScore,
                'absences_count' => $absences->count(),
            ],
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'watching' => $watching,
            'exams' => $exams,
            'assignments' => $assignments,
            'absences' => $absences,
        ]);
    }

    private function decodeAutoReview(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array{correct: int, graded: int, percent: float|null}
     */
    private function autoReviewCounts(?array $auto): array
    {
        $items = $auto['items'] ?? [];
        if (! is_array($items)) {
            return ['correct' => 0, 'graded' => 0, 'percent' => null];
        }
        $graded = 0;
        $correct = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $status = $item['status'] ?? '';
            if (! in_array($status, ['correct', 'incorrect'], true)) {
                continue;
            }
            $graded++;
            if ($status === 'correct') {
                $correct++;
            }
        }
        $percent = $graded > 0 ? round(($correct / $graded) * 100, 1) : null;

        return ['correct' => $correct, 'graded' => $graded, 'percent' => $percent];
    }

    private function mapExamReportRow(object $row): array
    {
        $score = $row->score !== null ? (float) $row->score : null;
        $total = $row->total_points !== null ? (float) $row->total_points : null;
        $display = null;
        if ($score !== null && $total !== null && $total > 0) {
            $display = round($score, 1).'/'.round($total, 1);
        } elseif ($score !== null) {
            $display = (string) round($score, 1);
        } elseif ($row->submitted_at) {
            $display = 'مُسلّم — بانتظار التصحيح';
        } else {
            $display = 'لم يُسلّم';
        }

        return [
            'course_title' => $row->course_title,
            'exam_title' => $row->exam_title,
            'score' => $score,
            'total_points' => $total,
            'grade_display' => $display,
            'submitted_at' => $row->submitted_at,
        ];
    }

    private function mapAssignmentReportRow(object $row): array
    {
        $manual = $row->score !== null ? (float) $row->score : null;
        $auto = $this->decodeAutoReview($row->auto_review ?? null);
        $counts = $this->autoReviewCounts($auto);
        $summary = is_array($auto) ? trim((string) ($auto['summary'] ?? '')) : '';
        if ($summary === '' && ! empty($row->feedback)) {
            $summary = trim((string) $row->feedback);
        }

        $display = null;
        $numeric = null;
        if ($manual !== null) {
            $display = (string) round($manual, 1);
            $numeric = $manual;
        } elseif ($counts['graded'] > 0) {
            $display = "تصحيح آلي: {$counts['correct']} من {$counts['graded']}";
            if ($summary !== '') {
                $display .= " — {$summary}";
            }
            $numeric = $counts['percent'];
        } elseif ($summary !== '') {
            $display = $summary;
        } elseif ($row->submitted_at) {
            $display = 'مُسلّم — بانتظار تصحيح المعلّm';
        } else {
            $display = 'لم يُسلّم';
        }

        return [
            'course_title' => $row->course_title,
            'assignment_title' => $row->assignment_title,
            'score' => $manual,
            'numeric_score' => $numeric,
            'grade_display' => $display,
            'auto_review_summary' => $summary !== '' ? $summary : null,
            'auto_correct' => $counts['correct'],
            'auto_graded' => $counts['graded'],
            'submitted_at' => $row->submitted_at,
        ];
    }
}