<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingPeriod extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'school_cycle_id',
        'name',
        'sequence',
        'starts_on',
        'ends_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function schoolCycle(): BelongsTo
    {
        return $this->belongsTo(SchoolCycle::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }
}