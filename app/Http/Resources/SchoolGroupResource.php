<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_cycle_id' => $this->school_cycle_id,
            'grade_level' => $this->grade_level,
            'section' => $this->section,
            'shift' => $this->shift,
            'capacity' => $this->capacity,
            'classroom' => $this->classroom,
            'is_active' => $this->is_active,

            'school_cycle' => $this->whenLoaded(
                'schoolCycle',
                fn (): array => [
                    'id' => $this->schoolCycle->id,
                    'campus_id' =>
                        $this->schoolCycle->campus_id,
                    'name' => $this->schoolCycle->name,
                    'starts_on' =>
                        $this->schoolCycle->starts_on?->toDateString(),
                    'ends_on' =>
                        $this->schoolCycle->ends_on?->toDateString(),
                    'status' => $this->schoolCycle->status,
                    'is_current' =>
                        $this->schoolCycle->is_current,
                ]
            ),

            'enrollments_count' => $this->whenCounted(
                'enrollments'
            ),

            'teaching_assignments_count' => $this->whenCounted(
                'teachingAssignments'
            ),

            'created_at' =>
                $this->created_at?->toISOString(),
            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}