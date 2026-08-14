<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'attendance_session_id',
        'enrollment_id',
        'status',
        'minutes_late',
        'notes',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'minutes_late' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(
            AttendanceSession::class,
            'attendance_session_id'
        );
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(
            Enrollment::class,
            'enrollment_id'
        );
    }
}