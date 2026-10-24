<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonComplianceRecord;
use App\Models\LessonProgress;
use App\Models\StudentAlert;

class LessonComplianceService
{
    public function onLessonPublished(Lesson $newLesson): void
    {
        $course = $newLesson->course ?? Course::find($newLesson->course_id);
        if (! $course) {
            return;
        }

        $previous = Lesson::query()
            ->where('course_id', $course->id)
            ->where('id', '!=', $newLesson->id)
            ->where('is_review', false)
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first();

        if (! $previous) {
            return;
        }

        $studentIds = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->pluck('student_id');

        foreach ($studentIds as $studentId) {
            if ($this->studentWatchedLesson((int) $studentId, $previous->id)) {
                continue;
            }

            LessonComplianceRecord::firstOrCreate(
                [
                    'student_id' => $studentId,
                    'missed_lesson_id' => $previous->id,
                    'kind' => 'absence',
                ],
                [
                    'course_id' => $course->id,
                    'triggered_by_lesson_id' => $newLesson->id,
                    'recorded_at' => now(),
                ]
            );

            StudentAlert::create([
                'student_id' => $studentId,
                'course_id' => $course->id,
                'lesson_id' => $previous->id,
                'type' => 'absence',
                'title' => 'غياب عن حصة',
                'body' => "لم تُكمل مشاهدة «{$previous->title}» قبل نزول حصة جديدة في «{$course->title}».",
            ]);
        }
    }

    private function studentWatchedLesson(int $studentId, int $lessonId): bool
    {
        $progress = LessonProgress::query()
            ->where('student_id', $studentId)
            ->where('lesson_id', $lessonId)
            ->first();

        if (! $progress) {
            return false;
        }
        if ($progress->completed_at !== null) {
            return true;
        }

        return (int) $progress->watched_seconds >= 60;
    }
}
