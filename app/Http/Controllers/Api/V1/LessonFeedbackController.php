<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ChecksStudentCourseAccess;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LessonFeedbackController extends Controller
{
    use ChecksStudentCourseAccess;

    public function store(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->role === 'student', 403);
        $this->ensureStudentCanAccessCourse($request, $lesson->course_id);

        $data = $request->validate([
            'reaction' => ['required', Rule::in(['like', 'dislike'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['reaction'] === 'dislike' && empty(trim((string) ($data['reason'] ?? '')))) {
            return response()->json(['message' => 'يجب كتابة سبب عدم الإعجاب.'], 422);
        }

        if ($data['reaction'] === 'like') {
            $data['reason'] = null;
        }

        $feedback = LessonFeedback::updateOrCreate(
            ['lesson_id' => $lesson->id, 'student_id' => $request->user()->id],
            ['reaction' => $data['reaction'], 'reason' => $data['reason'] ?? null]
        );

        return response()->json($feedback);
    }

    public function mine(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->role === 'student', 403);
        $this->ensureStudentCanAccessCourse($request, $lesson->course_id);

        return LessonFeedback::query()
            ->where('lesson_id', $lesson->id)
            ->where('student_id', $request->user()->id)
            ->first();
    }
}
