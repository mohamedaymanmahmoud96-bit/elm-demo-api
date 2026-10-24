<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonComplianceRecord extends Model
{
    protected $fillable = [
        'student_id',
        'course_id',
        'missed_lesson_id',
        'triggered_by_lesson_id',
        'kind',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function missedLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'missed_lesson_id');
    }
}
