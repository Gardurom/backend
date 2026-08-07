<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;

class Teacher extends Model
{
    use HasUuidV7, SoftDeletes;


    protected $fillable = [
        'person_id',
        'campus_id',
        'employee_number',
        'professional_license',
        'hired_on',
        'terminated_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'hired_on' => 'date',
            'terminated_on' => 'date',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }
	
	public function gradedGrades(): HasMany
{
    return $this->hasMany(
        Grade::class,
        'graded_by_teacher_id'
    );
}

public function recordedAttendanceSessions(): HasMany
{
    return $this->hasMany(
        AttendanceSession::class,
        'recorded_by_teacher_id'
    );
}
}