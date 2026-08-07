<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;

class Person extends Model
{
    use HasUuidV7, SoftDeletes;


    protected $fillable = [
        'first_name',
        'middle_name',
        'paternal_surname',
        'maternal_surname',
        'curp',
        'birth_date',
        'sex',
        'email',
        'phone',
        'emergency_phone',
        'additional_data',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'additional_data' => 'array',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    public function getFullNameAttribute(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->paternal_surname,
            $this->maternal_surname,
        ])->filter()->implode(' ');
    }
}