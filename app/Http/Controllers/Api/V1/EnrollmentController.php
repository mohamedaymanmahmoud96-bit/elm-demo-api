<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Enrollment::with(['course.subject', 'course.teacher:id,name']);
        if ($request->user()->role === 'student') {
            $query->where('student_id', $request->user()->id);
        } elseif ($request->user()->role === 'teacher') {
            $courseIds = Course::query()->where('teacher_id', $request->user()->id)->pluck('id');
            $query->whereIn('course_id', $courseIds);
        } elseif (! in_array($request->user()->role, ['admin', 'finance', 'assistant'], true)) {
            abort(403);
        }

        return $query->latest()->paginate(15);
    }

    public function store(Request $request, Course $course)
    {
        abort_unless($request->user()->role === 'student', 403);
        $enrollment = Enrollment::firstOrCreate(
            ['student_id' => $request->user()->id, 'course_id' => $course->id],
            ['status' => 'pending']
        );

        return response()->json($enrollment->load('course'), $enrollment->wasRecentlyCreated ? 201 : 200);
    }

    public function updateStatus(Request $request, Enrollment $enrollment)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'finance']), 403);
        $data = $request->validate([
            'status' => ['required', 'in:pending,active,expired,cancelled'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
        $enrollment->update($data);

        return $enrollment->fresh()->load('course');
    }
}