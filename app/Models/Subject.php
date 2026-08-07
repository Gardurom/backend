<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;

class Subject extends Model
{
    use HasUuidV7, SoftDeletes;

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
        return $this->hasMany(TeachingAssignment::class);
    }
}
