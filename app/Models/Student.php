<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuidV7;

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
}