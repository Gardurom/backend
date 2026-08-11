<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewSensitive = (bool) $request->attributes->get(
            'can_view_sensitive_students',
            false
        );

        $person = $this->whenLoaded('person');
        $campus = $this->whenLoaded('campus');

        return [
            'id' => $this->id,
            'campus_id' => $this->campus_id,
            'person_id' => $this->person_id,
            'enrollment_number' => $this->enrollment_number,
            'enrolled_on' => $this->enrolled_on?->format('Y-m-d'),
            'withdrawn_on' => $this->withdrawn_on?->format('Y-m-d'),
            'status' => $this->status,
            'notes' => $this->notes,

            'person' => $person
                ? [
                    'id' => $person->id,
                    'full_name' => $person->full_name,
                    'first_name' => $person->first_name,
                    'middle_name' => $person->middle_name,
                    'paternal_surname' => $person->paternal_surname,
                    'maternal_surname' => $person->maternal_surname,
                    'birth_date' => $person->birth_date
                        ?->format('Y-m-d'),
                    'sex' => $person->sex,

                    'curp' => $canViewSensitive
                        ? $person->curp
                        : null,

                    'curp_masked' => $this->maskCurp(
                        $person->curp
                    ),

                    'email' => $canViewSensitive
                        ? $person->email
                        : null,

                    'phone' => $canViewSensitive
                        ? $person->phone
                        : null,

                    'emergency_phone' => $canViewSensitive
                        ? $person->emergency_phone
                        : null,

                    'additional_data' => $canViewSensitive
                        ? $person->additional_data
                        : null,
                ]
                : null,

            'campus' => $campus
                ? [
                    'id' => $campus->id,
                    'code' => $campus->code,
                    'name' => $campus->name,
                ]
                : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function maskCurp(?string $curp): ?string
    {
        if (! $curp || strlen($curp) !== 18) {
            return null;
        }

        return substr($curp, 0, 4)
            .'**********'
            .substr($curp, -4);
    }
}