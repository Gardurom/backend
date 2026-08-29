<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceStudentState extends Model
{
    protected $table = 'geofence_student_states';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_inside' => 'boolean',
            'last_position_captured_at' => 'datetime',
            'state_changed_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function lastPosition(): BelongsTo
    {
        return $this->belongsTo(
            StudentPosition::class,
            'last_position_id'
        );
    }
}