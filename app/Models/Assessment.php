<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model
{
    use HasUuidV7, SoftDeletes;

    protected $fillable = [
        'teaching_assignment_id',
        'grading_period_id',
        'name',
        'description',
        'type',
        'maximum_score',
        'weight',
        'due_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'maximum_score' => 'decimal:2',
            'weight' => 'decimal:2',
            'due_at' => 'datetime',
        ];
    }

    public function teachingAssignment(): BelongsTo
    {
        return $this->belongsTo(TeachingAssignment::class);
    }

    public function gradingPeriod(): BelongsTo
    {
        return $this->belongsTo(GradingPeriod::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}