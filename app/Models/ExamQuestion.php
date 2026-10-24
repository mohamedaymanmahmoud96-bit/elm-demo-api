<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Exam;

class ExamQuestion extends Model
{
    use HasFactory;

    protected $fillable = ['exam_id', 'question', 'type', 'options', 'correct_answer', 'points', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'points' => 'decimal:2'];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
}