<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolGroup extends Model
{
    use HasUuidV7, SoftDeletes;


    protected $fillable = [
        'school_cycle_id',
        'grade_level',
        'section',
        'shift',
        'capacity',
        'classroom',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function schoolCycle(): BelongsTo
    {
        return $this->belongsTo(SchoolCycle::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(
            Enrollment::class,
            'school_group_id'
        );
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(
            TeachingAssignment::class,
            'school_group_id'
        );
    }
	
	public function attendanceSessions(): HasMany
{
    return $this->hasMany(
        AttendanceSession::class,
        'school_group_id'
    );
}
}