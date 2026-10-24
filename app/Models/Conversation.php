<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Message;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['title'];

    public function users(): BelongsToMany { return $this->belongsToMany(User::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }
}