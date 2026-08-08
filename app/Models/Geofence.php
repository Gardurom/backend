<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Geofence extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'campus_id',
        'created_by_user_id',
        'name',
        'description',
        'type',
        'radius_meters',
        'detect_entry',
        'detect_exit',
        'schedule',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'radius_meters' => 'decimal:2',
            'detect_entry' => 'boolean',
            'detect_exit' => 'boolean',
            'schedule' => 'array',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by_user_id'
        );
    }

    public function studentAssignments(): HasMany
    {
        return $this->hasMany(GeofenceStudentAssignment::class);
    }
}