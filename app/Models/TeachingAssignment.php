<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingAssignment extends Model
{
    use HasUuidV7, SoftDeletes;


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
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
	
	public function assessments(): HasMany
{
    return $this->hasMany(Assessment::class);
}

public function attendanceSessions(): HasMany
{
    return $this->hasMany(AttendanceSession::class);
}
}