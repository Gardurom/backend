<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'assessment_id',
        'enrollment_id',
        'graded_by_teacher_id',
        'score',
        'feedback',
        'graded_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function gradedByTeacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'graded_by_teacher_id'
        );
    }
}