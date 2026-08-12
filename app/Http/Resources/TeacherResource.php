<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewSensitive = $this->canViewSensitiveData(
            $request
        );

        return [
            'id' => $this->id,
            'campus_id' => $this->campus_id,
            'employee_number' => $this->employee_number,
            'professional_license' =>
                $this->professional_license,
            'hired_on' =>
                $this->hired_on?->toDateString(),
            'terminated_on' =>
                $this->terminated_on?->toDateString(),
            'status' => $this->status,

            'person' => $this->whenLoaded(
                'person',
                fn (): array => [
                    'id' => $this->person->id,
                    'first_name' =>
                        $this->person->first_name,
                    'middle_name' =>
                        $this->person->middle_name,
                    'paternal_surname' =>
                        $this->person->paternal_surname,
                    'maternal_surname' =>
                        $this->person->maternal_surname,
                    'full_name' =>
                        $this->person->full_name,
                    'birth_date' => $canViewSensitive
                        ? $this->person->birth_date
                            ?->toDateString()
                        : null,
                    'sex' => $this->person->sex,
                    'curp' => $canViewSensitive
                        ? $this->person->curp
                        : null,
                    'email' => $canViewSensitive
                        ? $this->person->email
                        : null,
                    'phone' => $canViewSensitive
                        ? $this->person->phone
                        : null,
                    'emergency_phone' => $canViewSensitive
                        ? $this->person->emergency_phone
                        : null,
                    'additional_data' => $canViewSensitive
                        ? $this->person->additional_data
                        : null,
                ]
            ),

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

    private function canViewSensitiveData(
        Request $request
    ): bool {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $campusId = (string) $this->campus_id;

        if ($campusId === '') {
            return false;
        }

        return app(AuthorizationService::class)
            ->userHasPermission(
                user: $user,
                permission: 'teachers.update',
                campusId: $campusId
            );
    }
}