<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Conversation;
use App\Models\TeacherSubscriptionPlan;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'parent_id',
        'grade',
        'governorate',
        'gender',
        'school_name',
        'parent_name',
        'parent_phone',
        'access_code',
        'account_status',
        'platform_expires_at',
        'subject_id',
        'grade_level_id',
        'employer_teacher_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'platform_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('role', 'student');
    }

    public function employerTeacher(): BelongsTo
    {
        return $this->belongsTo(self::class, 'employer_teacher_id');
    }

    public function assistants(): HasMany
    {
        return $this->hasMany(self::class, 'employer_teacher_id')->where('role', 'assistant');
    }

    public function subscriptionPlans(): HasMany
    {
        return $this->hasMany(TeacherSubscriptionPlan::class, 'teacher_id');
    }

    public function resolvedTeacherId(): ?int
    {
        return match ($this->role) {
            'teacher' => $this->id,
            'assistant' => $this->employer_teacher_id,
            default => null,
        };
    }

    public function owningTeacherId(): ?int
    {
        return $this->resolvedTeacherId();
    }

    public function managesTeacher(int $teacherId): bool
    {
        if (in_array($this->role, ['admin', 'finance'], true)) {
            return true;
        }
        if ($this->role === 'teacher') {
            return $this->id === $teacherId;
        }
        if ($this->role === 'assistant') {
            return (int) $this->employer_teacher_id === $teacherId;
        }

        return false;
    }
}
