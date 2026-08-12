<?php

namespace App\Http\Requests\Teacher;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $person = $this->input('person', []);

        if (is_array($person)) {
            $person = $this->normalizePerson($person);
        }

        $professionalLicense = $this->input(
            'professional_license'
        );

        $this->merge([
            'campus_id' => trim(
                (string) $this->input('campus_id')
            ),
            'employee_number' => mb_strtoupper(
                trim(
                    (string) $this->input(
                        'employee_number'
                    )
                )
            ),
            'professional_license' =>
                is_string($professionalLicense)
                    && trim($professionalLicense) !== ''
                        ? mb_strtoupper(
                            trim($professionalLicense)
                        )
                        : null,
            'status' => mb_strtolower(
                trim(
                    (string) $this->input(
                        'status',
                        'active'
                    )
                )
            ),
            'person' => $person,
        ]);
    }

    public function rules(): array
    {
        $campusId = (string) $this->input(
            'campus_id'
        );

        $headerCampusId = (string) $this->header(
            'X-Campus-ID'
        );

        return [
            'campus_id' => [
                'required',
                'uuid',
                Rule::in([$headerCampusId]),
                Rule::exists('campuses', 'id')
                    ->where(
                        fn ($query) => $query->where(
                            'is_active',
                            true
                        )
                    ),
            ],

            'employee_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(
                    'teachers',
                    'employee_number'
                )->where(
                    fn ($query) => $query->where(
                        'campus_id',
                        $campusId
                    )
                ),
            ],

            'professional_license' => [
                'nullable',
                'string',
                'max:50',
            ],

            'hired_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'terminated_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'status' => [
                'required',
                'string',
                Rule::in([
                    'active',
                    'inactive',
                    'leave',
                    'terminated',
                ]),
            ],

            'person' => [
                'required',
                'array',
            ],

            'person.first_name' => [
                'required',
                'string',
                'max:100',
            ],

            'person.middle_name' => [
                'nullable',
                'string',
                'max:100',
            ],

            'person.paternal_surname' => [
                'required',
                'string',
                'max:100',
            ],

            'person.maternal_surname' => [
                'nullable',
                'string',
                'max:100',
            ],

            'person.curp' => [
                'nullable',
                'string',
                'size:18',
                'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9][0-9]$/',
                Rule::unique('people', 'curp'),
            ],

            'person.birth_date' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'person.sex' => [
                'nullable',
                'string',
                Rule::in([
                    'female',
                    'male',
                    'unspecified',
                ]),
            ],

            'person.email' => [
                'nullable',
                'email:rfc',
                'max:254',
            ],

            'person.phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'person.emergency_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'person.additional_data' => [
                'sometimes',
                'array',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('hired_on')
                    || $validator->errors()->has(
                        'terminated_on'
                    )
                ) {
                    return;
                }

                $hiredOn = $this->input('hired_on');
                $terminatedOn = $this->input(
                    'terminated_on'
                );

                if (! $hiredOn || ! $terminatedOn) {
                    return;
                }

                if (
                    Carbon::parse($terminatedOn)
                        ->lt(Carbon::parse($hiredOn))
                ) {
                    $validator->errors()->add(
                        'terminated_on',
                        'La fecha de baja no puede ser anterior a la fecha de contratación.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'campus_id.in' =>
                'El plantel del cuerpo debe coincidir con X-Campus-ID.',

            'employee_number.regex' =>
                'El número de empleado solo puede contener letras, números, guiones y guiones bajos.',

            'employee_number.unique' =>
                'El número de empleado ya existe en este plantel.',

            'person.curp.unique' =>
                'La CURP ya está registrada.',

            'person.curp.regex' =>
                'La CURP no tiene un formato válido.',
        ];
    }

    private function normalizePerson(array $person): array
    {
        $textFields = [
            'first_name',
            'middle_name',
            'paternal_surname',
            'maternal_surname',
            'phone',
            'emergency_phone',
        ];

        foreach ($textFields as $field) {
            if (! array_key_exists($field, $person)) {
                continue;
            }

            if (
                $person[$field] === null
                || trim((string) $person[$field]) === ''
            ) {
                $person[$field] = null;

                continue;
            }

            $person[$field] = preg_replace(
                '/\s+/',
                ' ',
                trim((string) $person[$field])
            );
        }

        if (array_key_exists('curp', $person)) {
            $person['curp'] = $person['curp']
                ? mb_strtoupper(
                    trim((string) $person['curp'])
                )
                : null;
        }

        if (array_key_exists('email', $person)) {
            $person['email'] = $person['email']
                ? mb_strtolower(
                    trim((string) $person['email'])
                )
                : null;
        }

        if (array_key_exists('sex', $person)) {
            $person['sex'] = $person['sex']
                ? mb_strtolower(
                    trim((string) $person['sex'])
                )
                : null;
        }

        return $person;
    }
}