<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasUuidV7;

class SchoolCycle extends Model
{
	use HasUuidV7;

    protected $fillable = [
        'campus_id',
        'name',
        'starts_on',
        'ends_on',
        'status',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function schoolGroups(): HasMany
    {
        return $this->hasMany(SchoolGroup::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
	
	public function gradingPeriods(): HasMany
	{
    return $this->hasMany(GradingPeriod::class);
	}
}