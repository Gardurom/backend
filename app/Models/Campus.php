<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;

class Campus extends Model
{
    use HasUuidV7, SoftDeletes;


    protected $fillable = [
        'code',
        'name',
        'official_key',
        'email',
        'phone',
        'street',
        'external_number',
        'internal_number',
        'neighborhood',
        'postal_code',
        'locality',
        'municipality',
        'state',
        'country_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function schoolCycles(): HasMany
    {
        return $this->hasMany(SchoolCycle::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }
	
	public function geofences(): HasMany
{
    return $this->hasMany(Geofence::class);
}

public function userRoleAssignments(): HasMany
{
    return $this->hasMany(UserRoleAssignment::class);
}
}