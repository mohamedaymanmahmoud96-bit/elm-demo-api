<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\LessonFeedback;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LessonInsightsController extends Controller
{
    public function show(Request $request, Lesson $lesson)
    {
        $lesson->load('course:id,title,teacher_id');
        abort_unless($request->user()->managesTeacher($lesson->course->teacher_id), 403);

        $viewers = DB::table('lesson_progress')
            ->join('users', 'users.id', '=', 'lesson_progress.student_id')
            ->where('lesson_progress.lesson_id', $lesson->id)
            ->select([
                'users.id as student_id',
                'users.name as student_name',
                'users.access_code as student_code',
                'lesson_progress.watched_seconds',
                'lesson_progress.completed_at',
                'lesson_progress.updated_at as last_watched_at',
            ])
            ->orderByDesc('lesson_progress.updated_at')
            ->get();

        $feedbacks = LessonFeedback::query()
            ->where('lesson_id', $lesson->id)
            ->with('student:id,name,access_code')
            ->latest()
            ->get()
            ->map(fn (LessonFeedback $f) => [
                'id' => $f->id,
                'reaction' => $f->reaction,
                'reason' => $f->reason,
                'student_id' => $f->student_id,
                'student_name' => $f->student?->name,
                'student_code' => $f->student?->access_code,
                'created_at' => $f->created_at,
            ]);

        $likes = $feedbacks->where('reaction', 'like')->count();
        $dislikes = $feedbacks->where('reaction', 'dislike')->count();

        $assignments = Assignment::query()
            ->where('lesson_id', $lesson->id)
            ->withCount('submissions')
            ->latest()
            ->get();

        return response()->json([
            'lesson' => $lesson->only(['id', 'title', 'description', 'course_id']),
            'course_title' => $lesson->course?->title,
            'stats' => [
                'viewers_count' => $viewers->count(),
                'completed_count' => $viewers->filter(fn ($v) => $v->completed_at !== null)->count(),
                'likes_count' => $likes,
                'dislikes_count' => $dislikes,
            ],
            'viewers' => $viewers,
            'feedbacks' => $feedbacks->values(),
            'assignments' => $assignments,
        ]);
    }
}
