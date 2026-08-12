<?php

namespace App\Http\Requests\Teacher;

use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('employee_number')) {
            $values['employee_number'] = mb_strtoupper(
                trim(
                    (string) $this->input(
                        'employee_number'
                    )
                )
            );
        }

        if ($this->exists('professional_license')) {
            $professionalLicense = $this->input(
                'professional_license'
            );

            $values['professional_license'] =
                is_string($professionalLicense)
                    && trim($professionalLicense) !== ''
                        ? mb_strtoupper(
                            trim($professionalLicense)
                        )
                        : null;
        }

        if ($this->exists('status')) {
            $values['status'] = mb_strtolower(
                trim(
                    (string) $this->input('status')
                )
            );
        }

        if ($this->exists('person')) {
            $person = $this->input('person');

            if (is_array($person)) {
                $values['person'] = $this->normalizePerson(
                    $person
                );
            }
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        /** @var Teacher $teacher */
        $teacher = $this->route('teacher');

        return [
            'employee_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique(
                    'teachers',
                    'employee_number'
                )
                    ->where(
                        fn ($query) => $query->where(
                            'campus_id',
                            $teacher->campus_id
                        )
                    )
                    ->ignore($teacher->id),
            ],

            'professional_license' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'hired_on' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],

            'terminated_on' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],

            'status' => [
                'sometimes',
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
                'sometimes',
                'required',
                'array',
            ],

            'person.first_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'person.middle_name' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'person.paternal_surname' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'person.maternal_surname' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'person.curp' => [
                'sometimes',
                'nullable',
                'string',
                'size:18',
                'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9][0-9]$/',
                Rule::unique('people', 'curp')
                    ->ignore($teacher->person_id),
            ],

            'person.birth_date' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'person.sex' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'female',
                    'male',
                    'unspecified',
                ]),
            ],

            'person.email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:254',
            ],

            'person.phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'person.emergency_phone' => [
                'sometimes',
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

                /** @var Teacher $teacher */
                $teacher = $this->route('teacher');

                $hiredOn = $this->input(
                    'hired_on',
                    $teacher->hired_on?->format('Y-m-d')
                );

                $terminatedOn = $this->input(
                    'terminated_on',
                    $teacher->terminated_on?->format(
                        'Y-m-d'
                    )
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