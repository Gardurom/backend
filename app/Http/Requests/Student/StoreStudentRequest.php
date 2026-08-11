<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $person = $this->input('person', []);

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
        }

        $this->merge([
            'campus_id' => trim((string) $this->input('campus_id')),
            'enrollment_number' => strtoupper(
                trim((string) $this->input('enrollment_number'))
            ),
            'person' => $person,
        ]);
    }

    public function rules(): array
    {
        $campusId = (string) $this->input('campus_id');
        $headerCampusId = (string) $this->header('X-Campus-ID');

        return [
            'campus_id' => [
                'required',
                'uuid',
                Rule::in([$headerCampusId]),
                Rule::exists('campuses', 'id')->where(
                    fn ($query) => $query->where('is_active', true)
                ),
            ],

            'enrollment_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('students', 'enrollment_number')
                    ->where(
                        fn ($query) => $query->where(
                            'campus_id',
                            $campusId
                        )
                    ),
            ],

            'enrolled_on' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'status' => [
                'sometimes',
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
                'nullable',
                'string',
                'max:5000',
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

    public function messages(): array
    {
        return [
            'campus_id.in' =>
                'El plantel del cuerpo debe coincidir con X-Campus-ID.',

            'enrollment_number.unique' =>
                'La matrícula ya existe en este plantel.',

            'person.curp.unique' =>
                'La CURP ya está registrada.',

            'person.curp.regex' =>
                'La CURP no tiene un formato válido.',
        ];
    }
}