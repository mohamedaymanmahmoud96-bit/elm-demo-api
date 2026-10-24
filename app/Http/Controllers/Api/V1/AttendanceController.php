<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Course;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::with(['student:id,name,access_code', 'course:id,title']);
        if ($request->user()->role === 'student') {
            $query->where('student_id', $request->user()->id);
        } elseif ($request->user()->role === 'teacher') {
            $query->where('teacher_id', $request->user()->id);
        } elseif (!in_array($request->user()->role, ['admin', 'assistant'])) {
            abort(403);
        }

        return $query->latest('attendance_date')->paginate(30);
    }

    public function store(Request $request, Course $course)
    {
        abort_unless(in_array($request->user()->role, ['admin', 'teacher']), 403);
        abort_unless($request->user()->managesTeacher($course->teacher_id), 403);
        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'attendance_date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,late,excused'],
            'note' => ['nullable', 'string'],
        ]);
        $attendance = Attendance::updateOrCreate(
            ['student_id' => $data['student_id'], 'course_id' => $course->id, 'attendance_date' => $data['attendance_date']],
            [...$data, 'teacher_id' => $request->user()->id, 'course_id' => $course->id]
        );

        return response()->json($attendance->load(['student:id,name,access_code', 'course:id,title']), 201);
    }
}