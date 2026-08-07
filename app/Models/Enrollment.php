<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    use HasUuidV7, SoftDeletes;


    protected $fillable = [
        'student_id',
        'school_cycle_id',
        'school_group_id',
        'enrolled_on',
        'withdrawn_on',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'withdrawn_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolCycle(): BelongsTo
    {
        return $this->belongsTo(SchoolCycle::class);
    }

    public function schoolGroup(): BelongsTo
    {
        return $this->belongsTo(
            SchoolGroup::class,
            'school_group_id'
        );
    }
	
	public function grades(): HasMany
{
    return $this->hasMany(Grade::class);
}

public function attendanceRecords(): HasMany
{
    return $this->hasMany(AttendanceRecord::class);
}
}