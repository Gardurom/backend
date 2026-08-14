<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'attendance_session_id' =>
                $this->attendance_session_id,

            'enrollment_id' =>
                $this->enrollment_id,

            'status' => $this->status,

            'minutes_late' =>
                $this->minutes_late,

            'notes' => $this->notes,

            'recorded_at' =>
                $this->recorded_at?->toISOString(),

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

            'created_at' =>
                $this->created_at?->toISOString(),

            'updated_at' =>
                $this->updated_at?->toISOString(),
        ];
    }
}