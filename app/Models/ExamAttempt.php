<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Exam;

class ExamAttempt extends Model
{
    use HasFactory;

    protected $fillable = ['exam_id', 'student_id', 'score', 'answers', 'started_at', 'submitted_at'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'answers' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function student(): BelongsTo { return $this->belongsTo(User::class, 'student_id'); }
}