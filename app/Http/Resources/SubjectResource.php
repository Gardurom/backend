<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campus_id' => $this->campus_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'weekly_hours' => $this->weekly_hours,
            'is_active' => $this->is_active,

            'campus' => $this->whenLoaded(
                'campus',
                fn (): array => [
                    'id' => $this->campus->id,
                    'code' => $this->campus->code,
                    'name' => $this->campus->name,
                ]
            ),

            'teaching_assignments_count' =>
                $this->whenCounted(
                    'teachingAssignments'
                ),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}