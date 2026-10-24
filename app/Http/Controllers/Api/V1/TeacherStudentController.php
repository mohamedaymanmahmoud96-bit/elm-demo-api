<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentRemovalRequest;
use App\Models\User;
use Illuminate\Http\Request;

class TeacherStudentController extends Controller
{
    public function removeFromPlatform(Request $request, User $student)
    {
        abort_unless($request->user()->role === 'teacher', 403);
        abort_unless($student->role === 'student', 422, 'يمكن إزالة الطلاب فقط.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $teacherId = $request->user()->id;
        $courseIds = Course::query()->where('teacher_id', $teacherId)->pluck('id');
        abort_if($courseIds->isEmpty(), 422, 'لا يوجد كورس مرتبط بك.');

        $hasEnrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courseIds)
            ->exists();
        abort_unless($hasEnrollment, 422, 'هذا الطالب غير مشترك في موادك.');

        Enrollment::query()
            ->where('student_id', $student->id)
            ->whereIn('course_id', $courseIds)
            ->update(['status' => 'cancelled']);

        $log = StudentRemovalRequest::create([
            'teacher_id' => $teacherId,
            'student_id' => $student->id,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'تم تسجيل طلب الإزالة وإبلاغ الإدارة.',
            'request' => $log->load(['teacher:id,name', 'student:id,name,access_code']),
        ], 201);
    }

    public function removalRequestsForAdmin(Request $request)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'finance'], true), 403);

        return StudentRemovalRequest::query()
            ->with(['teacher:id,name', 'student:id,name,access_code,phone'])
            ->latest()
            ->limit(200)
            ->get();
    }
}
