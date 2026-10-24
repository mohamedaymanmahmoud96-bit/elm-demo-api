<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeLevel extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order'];

    public function teachers(): HasMany
    {
        return $this->hasMany(User::class, 'grade_level_id');
    }
}
