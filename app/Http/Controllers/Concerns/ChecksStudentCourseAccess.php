<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Enrollment;
use Illuminate\Http\Request;

trait ChecksStudentCourseAccess
{
    protected function ensureStudentCanAccessCourse(Request $request, int $courseId): void
    {
        if ($request->user()?->role !== 'student') {
            return;
        }

        $enrolled = Enrollment::query()
            ->where('student_id', $request->user()->id)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        abort_unless($enrolled, 403, 'هذا المحتوى خاص بكورس لم تشترك فيه مع هذا المعلّم.');
    }
}
