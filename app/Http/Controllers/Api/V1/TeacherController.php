<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $teacherIds = Course::query()
            ->where('status', 'published')
            ->distinct()
            ->pluck('teacher_id');

        return User::query()
            ->whereIn('id', $teacherIds)
            ->where('role', 'teacher')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();
    }

    public function students(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['teacher', 'admin', 'assistant'], true), 403);

        $teacherId = $request->user()->resolvedTeacherId();
        $courseIds = Course::query()
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->pluck('id');

        $studentIds = Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', 'active')
            ->distinct()
            ->pluck('student_id');

        return User::query()
            ->whereIn('id', $studentIds)
            ->where('role', 'student')
            ->orderBy('name')
            ->get(['id', 'name', 'access_code', 'grade']);
    }
}
