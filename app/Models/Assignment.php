<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\AssignmentSubmission;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = ['course_id', 'lesson_id', 'teacher_id', 'title', 'description', 'questions', 'due_at'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'questions' => 'array',
        ];
    }
    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function lesson(): BelongsTo { return $this->belongsTo(Lesson::class); }
    public function teacher(): BelongsTo { return $this->belongsTo(User::class, 'teacher_id'); }
    public function submissions(): HasMany { return $this->hasMany(AssignmentSubmission::class); }
}