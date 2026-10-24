<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParentController extends Controller
{
    public function children(Request $request)
    {
        abort_unless($request->user()->role === 'parent', 403);

        return User::query()
            ->where('role', 'student')
            ->where('parent_id', $request->user()->id)
            ->select('id', 'name', 'email', 'phone', 'access_code', 'grade', 'governorate', 'created_at')
            ->latest()
            ->get();
    }

    public function linkChild(Request $request)
    {
        abort_unless($request->user()->role === 'parent', 403);

        $data = $request->validate([
            'access_code' => ['required', 'string', 'max:32'],
        ]);

        $student = User::query()
            ->where('role', 'student')
            ->where('access_code', $data['access_code'])
            ->firstOrFail();

        $student->update(['parent_id' => $request->user()->id]);

        return response()->json([
            'message' => 'تم ربط الطالب بنجاح.',
            'student' => $student->only(['id', 'name', 'access_code', 'grade', 'governorate']),
        ]);
    }

    public function childReport(Request $request, User $student)
    {
        abort_unless($request->user()->role === 'parent', 403);
        abort_unless($student->role === 'student' && $student->parent_id === $request->user()->id, 403);

        $watching = DB::table('lesson_progress')
            ->join('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
            ->join('courses', 'courses.id', '=', 'lessons.course_id')
            ->where('lesson_progress.student_id', $student->id)
            ->select([
                'courses.title as course_title',
                'lessons.title as lesson_title',
                'lesson_progress.completed_at',
            ])
            ->latest('lesson_progress.updated_at')
            ->limit(20)
            ->get();

        $exams = DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->where('exam_attempts.student_id', $student->id)
            ->select('exams.title as exam_title', 'exam_attempts.score', 'exam_attempts.submitted_at')
            ->latest('exam_attempts.submitted_at')
            ->limit(10)
            ->get();

        $enrollments = DB::table('enrollments')
            ->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->where('enrollments.student_id', $student->id)
            ->select('courses.title as course_title', 'enrollments.status', 'enrollments.expires_at')
            ->get();

        return response()->json([
            'student' => $student->only(['id', 'name', 'access_code', 'grade']),
            'enrollments' => $enrollments,
            'watching' => $watching,
            'exams' => $exams,
        ]);
    }
}
