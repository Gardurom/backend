<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $percentage = null;

        if (
            $this->score !== null
            && $this->assessment
            && (float) $this->assessment->maximum_score > 0
        ) {
            $percentage = round(
                ((float) $this->score
                    / (float) $this->assessment
                        ->maximum_score) * 100,
                2
            );
        }

        return [
            'id' => $this->id,
            'assessment_id' => $this->assessment_id,
            'enrollment_id' => $this->enrollment_id,

            'graded_by_teacher_id' =>
                $this->graded_by_teacher_id,

            'score' => $this->score,
            'percentage' => $percentage,
            'feedback' => $this->feedback,

            'graded_at' =>
                $this->graded_at?->toISOString(),

            'status' => $this->status,

            'assessment' => $this->whenLoaded(
                'assessment',
                fn (): array => [
                    'id' => $this->assessment->id,
                    'name' => $this->assessment->name,
                    'type' => $this->assessment->type,
                    'maximum_score' =>
                        $this->assessment->maximum_score,
                    'weight' =>
                        $this->assessment->weight,
                    'status' =>
                        $this->assessment->status,
                ]
            ),

            'enrollment' => $this->whenLoaded(
                'enrollment',
                fn (): array => [
                    'id' => $this->enrollment->id,
                    'status' =>
                        $this->enrollment->status,

                    'student' =>
                        $this->enrollment
                            ->relationLoaded('student')
                            ? [
                                'id' =>
                                    $this->enrollment
                                        ->student->id,
                                'enrollment_number' =>
                                    $this->enrollment
                                        ->student
                                        ->enrollment_number,
                                'full_name' =>
                                    $this->enrollment
                                        ->student
                                        ->relationLoaded(
                                            'person'
                                        )
                                            ? $this->enrollment
                                                ->student
                                                ->person
                                                ->full_name
                                            : null,
                            ]
                            : null,
                ]
            ),

            'graded_by_teacher' =>
                $this->whenLoaded(
                    'gradedByTeacher',
                    fn (): ?array => $this
                        ->gradedByTeacher
                        ? [
                            'id' =>
                                $this
                                    ->gradedByTeacher->id,
                            'employee_number' =>
                                $this
                                    ->gradedByTeacher
                                    ->employee_number,
                            'full_name' =>
                                $this
                                    ->gradedByTeacher
                                    ->relationLoaded('person')
                                        ? $this
                                            ->gradedByTeacher
                                            ->person
                                            ->full_name
                                        : null,
                        ]
                        : null
                ),

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}