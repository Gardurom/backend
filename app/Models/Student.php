<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    use HasUuidV7, SoftDeletes;


    protected $fillable = [
        'person_id',
        'campus_id',
        'enrollment_number',
        'enrolled_on',
        'withdrawn_on',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'withdrawn_on' => 'date',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
	
	public function addresses(): HasMany
{
    return $this->hasMany(StudentAddress::class);
}

	public function primaryAddress(): HasOne
{
    return $this->hasOne(StudentAddress::class)
        ->where('is_primary', true);
}

public function geofenceAssignments(): HasMany
{
    return $this->hasMany(GeofenceStudentAssignment::class);
}
}