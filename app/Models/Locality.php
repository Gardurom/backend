<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Locality extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'parent_id',
        'official_code',
        'name',
        'type',
        'population',
        'area_square_km',
        'properties',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'population' => 'integer',
            'area_square_km' => 'decimal:4',
            'properties' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Locality::class, 'parent_id');
    }

    public function studentAddresses(): HasMany
    {
        return $this->hasMany(StudentAddress::class);
    }
}