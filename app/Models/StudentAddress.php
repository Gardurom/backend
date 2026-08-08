<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StudentAddress extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'student_id',
        'locality_id',
        'street',
        'external_number',
        'internal_number',
        'neighborhood',
        'postal_code',
        'address_reference',
        'accuracy_meters',
        'location_source',
        'is_primary',
        'is_verified',
        'valid_from',
        'valid_until',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'accuracy_meters' => 'decimal:2',
            'is_primary' => 'boolean',
            'is_verified' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }
}