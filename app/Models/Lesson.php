<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Course;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id', 'title', 'description', 'video_provider', 'video_id', 'video_path',
        'duration_seconds', 'sort_order', 'is_preview', 'is_review', 'term_number', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_preview' => 'boolean',
            'is_review' => 'boolean',
            'term_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}