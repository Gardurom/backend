<?php

namespace App\Http\Requests\Student;

use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $person = $this->input('person');

        if (is_array($person)) {
            if (array_key_exists('curp', $person)) {
                $person['curp'] = $person['curp']
                    ? strtoupper(trim((string) $person['curp']))
                    : null;
            }

            if (array_key_exists('email', $person)) {
                $person['email'] = $person['email']
                    ? strtolower(trim((string) $person['email']))
                    : null;
            }

            $this->merge([
                'person' => $person,
            ]);
        }

        if ($this->has('enrollment_number')) {
            $this->merge([
                'enrollment_number' => strtoupper(
                    trim((string) $this->input('enrollment_number'))
                ),
            ]);
        }
    }

    public function rules(): array
    {
        /** @var Student $student */
        $student = $this->route('student');

        return [
            'enrollment_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('students', 'enrollment_number')
                    ->where(
                        fn ($query) => $query->where(
                            'campus_id',
                            $student->campus_id
                        )
                    )
                    ->ignore($student->id),
            ],

            'enrolled_on' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'withdrawn_on' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'applicant',
                    'active',
                    'inactive',
                    'graduated',
                    'withdrawn',
                    'suspended',
                ]),
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
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
                    ->ignore($student->person_id),
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
                    $validator->errors()->has('enrolled_on')
                    || $validator->errors()->has('withdrawn_on')
                ) {
                    return;
                }

                /** @var Student $student */
                $student = $this->route('student');

                $enrolledOn = $this->input(
                    'enrolled_on',
                    $student->enrolled_on?->format('Y-m-d')
                );

                $withdrawnOn = $this->input(
                    'withdrawn_on',
                    $student->withdrawn_on?->format('Y-m-d')
                );

                if (! $enrolledOn || ! $withdrawnOn) {
                    return;
                }

                if (
                    Carbon::parse($withdrawnOn)
                        ->lt(Carbon::parse($enrolledOn))
                ) {
                    $validator->errors()->add(
                        'withdrawn_on',
                        'La fecha de baja no puede ser anterior al ingreso.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'enrollment_number.unique' =>
                'La matrícula ya existe en este plantel.',

            'person.curp.unique' =>
                'La CURP ya está registrada.',

            'person.curp.regex' =>
                'La CURP no tiene un formato válido.',
        ];
    }
}