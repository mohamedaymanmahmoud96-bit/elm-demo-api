<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id', 'teacher_id', 'title', 'description', 'duration_minutes',
        'total_points', 'exam_mode', 'max_attempts', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(User::class, 'teacher_id'); }
    public function questions(): HasMany { return $this->hasMany(ExamQuestion::class)->orderBy('sort_order'); }
    public function attempts(): HasMany { return $this->hasMany(ExamAttempt::class); }
}