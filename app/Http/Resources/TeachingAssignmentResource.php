<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeachingAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_group_id' =>
                $this->school_group_id,
            'subject_id' => $this->subject_id,
            'teacher_id' => $this->teacher_id,
            'starts_on' =>
                $this->starts_on?->toDateString(),
            'ends_on' =>
                $this->ends_on?->toDateString(),
            'status' => $this->status,

            'school_group' => $this->whenLoaded(
                'schoolGroup',
                fn (): array => [
                    'id' => $this->schoolGroup->id,
                    'grade_level' =>
                        $this->schoolGroup->grade_level,
                    'section' =>
                        $this->schoolGroup->section,
                    'shift' =>
                        $this->schoolGroup->shift,
                    'classroom' =>
                        $this->schoolGroup->classroom,
                    'school_cycle' =>
                        $this->schoolGroup->relationLoaded(
                            'schoolCycle'
                        )
                            ? [
                                'id' =>
                                    $this->schoolGroup
                                        ->schoolCycle->id,
                                'name' =>
                                    $this->schoolGroup
                                        ->schoolCycle->name,
                                'campus_id' =>
                                    $this->schoolGroup
                                        ->schoolCycle
                                        ->campus_id,
                            ]
                            : null,
                ]
            ),

            'subject' => $this->whenLoaded(
                'subject',
                fn (): array => [
                    'id' => $this->subject->id,
                    'code' => $this->subject->code,
                    'name' => $this->subject->name,
                    'weekly_hours' =>
                        $this->subject->weekly_hours,
                ]
            ),

            'teacher' => $this->whenLoaded(
                'teacher',
                fn (): array => [
                    'id' => $this->teacher->id,
                    'employee_number' =>
                        $this->teacher->employee_number,
                    'status' =>
                        $this->teacher->status,
                    'full_name' =>
                        $this->teacher->relationLoaded(
                            'person'
                        )
                            ? $this->teacher
                                ->person
                                ->full_name
                            : null,
                ]
            ),

            'assessments_count' =>
                $this->whenCounted('assessments'),

            'attendance_sessions_count' =>
                $this->whenCounted(
                    'attendanceSessions'
                ),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}