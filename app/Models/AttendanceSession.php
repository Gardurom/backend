<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'school_group_id',
        'teaching_assignment_id',
        'recorded_by_teacher_id',
        'held_on',
        'starts_at',
        'ends_at',
        'type',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
        ];
    }

    public function schoolGroup(): BelongsTo
    {
        return $this->belongsTo(
            SchoolGroup::class,
            'school_group_id'
        );
    }

    public function teachingAssignment(): BelongsTo
    {
        return $this->belongsTo(TeachingAssignment::class);
    }

    public function recordedByTeacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'recorded_by_teacher_id'
        );
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}