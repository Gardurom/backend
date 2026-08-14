<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'teaching_assignment_id' =>
                $this->teaching_assignment_id,

            'grading_period_id' =>
                $this->grading_period_id,

            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'maximum_score' => $this->maximum_score,
            'weight' => $this->weight,

            'due_at' => $this->due_at
                ?->toISOString(),

            'status' => $this->status,

            'grading_period' => $this->whenLoaded(
                'gradingPeriod',
                fn (): array => [
                    'id' => $this->gradingPeriod->id,
                    'school_cycle_id' =>
                        $this->gradingPeriod
                            ->school_cycle_id,
                    'name' =>
                        $this->gradingPeriod->name,
                    'sequence' =>
                        $this->gradingPeriod->sequence,
                    'starts_on' =>
                        $this->gradingPeriod
                            ->starts_on
                            ?->toDateString(),
                    'ends_on' =>
                        $this->gradingPeriod
                            ->ends_on
                            ?->toDateString(),
                    'status' =>
                        $this->gradingPeriod->status,
                ]
            ),

            'teaching_assignment' =>
                $this->whenLoaded(
                    'teachingAssignment',
                    fn (): array => [
                        'id' =>
                            $this->teachingAssignment->id,

                        'status' =>
                            $this->teachingAssignment
                                ->status,

                        'subject' =>
                            $this->teachingAssignment
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

                        'teacher' =>
                            $this->teachingAssignment
                                ->relationLoaded('teacher')
                                ? [
                                    'id' =>
                                        $this
                                            ->teachingAssignment
                                            ->teacher->id,
                                    'employee_number' =>
                                        $this
                                            ->teachingAssignment
                                            ->teacher
                                            ->employee_number,
                                    'full_name' =>
                                        $this
                                            ->teachingAssignment
                                            ->teacher
                                            ->relationLoaded(
                                                'person'
                                            )
                                                ? $this
                                                    ->teachingAssignment
                                                    ->teacher
                                                    ->person
                                                    ->full_name
                                                : null,
                                ]
                                : null,

                        'school_group' =>
                            $this->teachingAssignment
                                ->relationLoaded(
                                    'schoolGroup'
                                )
                                ? [
                                    'id' =>
                                        $this
                                            ->teachingAssignment
                                            ->schoolGroup->id,
                                    'grade_level' =>
                                        $this
                                            ->teachingAssignment
                                            ->schoolGroup
                                            ->grade_level,
                                    'section' =>
                                        $this
                                            ->teachingAssignment
                                            ->schoolGroup
                                            ->section,
                                    'shift' =>
                                        $this
                                            ->teachingAssignment
                                            ->schoolGroup
                                            ->shift,
                                ]
                                : null,
                    ]
                ),

            'grades_count' =>
                $this->whenCounted('grades'),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}