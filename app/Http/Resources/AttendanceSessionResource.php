<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'school_group_id' =>
                $this->school_group_id,

            'teaching_assignment_id' =>
                $this->teaching_assignment_id,

            'recorded_by_teacher_id' =>
                $this->recorded_by_teacher_id,

            'held_on' =>
                $this->held_on?->toDateString(),

            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'type' => $this->type,
            'status' => $this->status,
            'notes' => $this->notes,

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
                ]
            ),

            'teaching_assignment' =>
                $this->whenLoaded(
                    'teachingAssignment',
                    fn (): ?array => $this
                        ->teachingAssignment
                        ? [
                            'id' =>
                                $this
                                    ->teachingAssignment->id,
                            'subject' =>
                                $this
                                    ->teachingAssignment
                                    ->relationLoaded('subject')
                                    ? [
                                        'id' =>
                                            $this
                                                ->teachingAssignment
                                                ->subject->id,
                                        'code' =>
                                            $this
                                                ->teachingAssignment
                                                ->subject->code,
                                        'name' =>
                                            $this
                                                ->teachingAssignment
                                                ->subject->name,
                                    ]
                                    : null,
                        ]
                        : null
                ),

            'recorded_by_teacher' =>
                $this->whenLoaded(
                    'recordedByTeacher',
                    fn (): ?array => $this
                        ->recordedByTeacher
                        ? [
                            'id' =>
                                $this
                                    ->recordedByTeacher->id,
                            'employee_number' =>
                                $this
                                    ->recordedByTeacher
                                    ->employee_number,
                            'full_name' =>
                                $this
                                    ->recordedByTeacher
                                    ->relationLoaded('person')
                                    ? $this
                                        ->recordedByTeacher
                                        ->person
                                        ->full_name
                                    : null,
                        ]
                        : null
                ),

            'records_count' =>
                $this->whenCounted('records'),

            'records' =>
                AttendanceRecordResource::collection(
                    $this->whenLoaded('records')
                ),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}