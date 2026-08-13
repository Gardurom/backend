<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeachingAssignment extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $fillable = [
        'school_group_id',
        'subject_id',
        'teacher_id',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function schoolGroup(): BelongsTo
    {
        return $this->belongsTo(
            SchoolGroup::class,
            'school_group_id'
        );
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(
            Subject::class,
            'subject_id'
        );
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'teacher_id'
        );
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(
            Assessment::class,
            'teaching_assignment_id'
        );
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(
            AttendanceSession::class,
            'teaching_assignment_id'
        );
    }
}