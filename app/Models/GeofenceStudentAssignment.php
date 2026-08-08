<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceStudentAssignment extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'geofence_id',
        'student_id',
        'authorized_by_user_id',
        'consent_reference',
        'authorized_at',
        'starts_at',
        'ends_at',
        'notification_settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'notification_settings' => 'array',
            'is_active' => 'boolean',
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

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'authorized_by_user_id'
        );
    }
}