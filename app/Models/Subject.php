<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subject extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $fillable = [
        'campus_id',
        'code',
        'name',
        'description',
        'weekly_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekly_hours' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(
            TeachingAssignment::class,
            'subject_id'
        );
    }
}