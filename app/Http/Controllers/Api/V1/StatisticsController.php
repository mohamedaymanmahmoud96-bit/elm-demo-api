<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher', 'assistant', 'finance']), 403);

        $teacherId = $request->user()->resolvedTeacherId();
        $courseIds = Course::query()
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->pluck('id');

        $enrolledStudents = Enrollment::query()
            ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('course_id', $courseIds))
            ->where('status', 'active')
            ->distinct('student_id')
            ->count('student_id');

        $lessonsWatched = LessonProgress::query()
            ->when($courseIds->isNotEmpty(), function ($q) use ($courseIds) {
                $q->whereIn('lesson_id', function ($sub) use ($courseIds) {
                    $sub->select('id')->from('lessons')->whereIn('course_id', $courseIds);
                });
            })
            ->whereNotNull('completed_at')
            ->count();

        $examStats = ExamAttempt::query()
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('exams.course_id', $courseIds))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN exam_attempts.score >= (exams.total_points * 0.5) THEN 1 ELSE 0 END) as passed')
            ->first();

        $pendingPayments = Payment::query()
            ->where('status', 'pending')
            ->when($teacherId, function ($q) use ($teacherId) {
                $q->where('metadata->teacher_id', $teacherId);
            })
            ->count();

        $assignmentSubmissions = AssignmentSubmission::query()
            ->join('assignments', 'assignments.id', '=', 'assignment_submissions.assignment_id')
            ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('assignments.course_id', $courseIds))
            ->whereNotNull('assignment_submissions.submitted_at')
            ->count();

        $charts = $this->buildCharts($courseIds);

        return response()->json([
            'enrolled_students' => $enrolledStudents,
            'lessons_watched' => $lessonsWatched,
            'exam_attempts' => (int) ($examStats->total ?? 0),
            'passed_exams' => (int) ($examStats->passed ?? 0),
            'failed_exams' => max(0, (int) ($examStats->total ?? 0) - (int) ($examStats->passed ?? 0)),
            'pending_payments' => $pendingPayments,
            'assignment_submissions' => $assignmentSubmissions,
            'total_students' => User::where('role', 'student')->count(),
            'total_courses' => $courseIds->count(),
            'total_teachers' => User::where('role', 'teacher')->count(),
            'charts' => $charts,
        ]);
    }

    private function buildCharts($courseIds): array
    {
        $days = 7;
        $labels = [];
        $lessonsSeries = [];
        $examsSeries = [];
        $assignmentsSeries = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $labels[] = $day->format('d/m');

            $lessonsSeries[] = LessonProgress::query()
                ->when($courseIds->isNotEmpty(), function ($q) use ($courseIds) {
                    $q->whereIn('lesson_id', function ($sub) use ($courseIds) {
                        $sub->select('id')->from('lessons')->whereIn('course_id', $courseIds);
                    });
                })
                ->whereNotNull('completed_at')
                ->whereDate('completed_at', $day)
                ->count();

            $examsSeries[] = ExamAttempt::query()
                ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('exams.course_id', $courseIds))
                ->whereDate('exam_attempts.submitted_at', $day)
                ->count();

            $assignmentsSeries[] = AssignmentSubmission::query()
                ->join('assignments', 'assignments.id', '=', 'assignment_submissions.assignment_id')
                ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('assignments.course_id', $courseIds))
                ->whereNotNull('assignment_submissions.submitted_at')
                ->whereDate('assignment_submissions.submitted_at', $day)
                ->count();
        }

        $courseEnrollment = Enrollment::query()
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('enrollments.course_id', $courseIds))
            ->where('enrollments.status', 'active')
            ->select('courses.title as title', DB::raw('COUNT(DISTINCT enrollments.student_id) as students'))
            ->groupBy('courses.id', 'courses.title')
            ->orderByDesc('students')
            ->limit(6)
            ->get()
            ->map(fn ($row) => ['title' => $row->title, 'students' => (int) $row->students])
            ->values()
            ->all();

        return [
            'activity_last_7_days' => [
                'labels' => $labels,
                'lessons_completed' => $lessonsSeries,
                'exam_attempts' => $examsSeries,
                'assignments_submitted' => $assignmentsSeries,
            ],
            'courses_enrollment' => $courseEnrollment,
        ];
    }
}
