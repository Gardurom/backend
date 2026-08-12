<?php

namespace App\Http\Requests\SchoolGroup;

use App\Models\SchoolGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSchoolGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'grade_level' => $this->normalizeText(
                $this->input('grade_level')
            ),
            'section' => $this->normalizeUppercase(
                $this->input('section')
            ),
            'shift' => $this->normalizeLowercase(
                $this->input('shift')
            ),
            'classroom' => $this->normalizeNullableText(
                $this->input('classroom')
            ),
        ]);
    }

    public function rules(): array
    {
        $campusId = (string) $this->attributes->get(
            'campus_id'
        );

        return [
            'school_cycle_id' => [
                'required',
                'uuid',
                Rule::exists('school_cycles', 'id')
                    ->where(
                        fn ($query) => $query->where(
                            'campus_id',
                            $campusId
                        )
                    ),
            ],
            'grade_level' => [
                'required',
                'string',
                'max:30',
            ],
            'section' => [
                'required',
                'string',
                'max:20',
            ],
            'shift' => [
                'required',
                'string',
                Rule::in([
                    'morning',
                    'afternoon',
                    'evening',
                    'full_time',
                ]),
            ],
            'capacity' => [
                'required',
                'integer',
                'between:1,500',
            ],
            'classroom' => [
                'nullable',
                'string',
                'max:50',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $exists = SchoolGroup::query()
                ->withTrashed()
                ->where(
                    'school_cycle_id',
                    $this->string(
                        'school_cycle_id'
                    )->toString()
                )
                ->where(
                    'grade_level',
                    $this->string(
                        'grade_level'
                    )->toString()
                )
                ->where(
                    'section',
                    $this->string(
                        'section'
                    )->toString()
                )
                ->where(
                    'shift',
                    $this->string(
                        'shift'
                    )->toString()
                )
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'section',
                    'Ya existe un grupo con el mismo ciclo, grado, sección y turno.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'school_cycle_id.required' =>
                'El ciclo escolar es obligatorio.',
            'school_cycle_id.exists' =>
                'El ciclo escolar no existe o no pertenece al plantel seleccionado.',
            'grade_level.required' =>
                'El grado escolar es obligatorio.',
            'section.required' =>
                'La sección es obligatoria.',
            'shift.required' =>
                'El turno es obligatorio.',
            'shift.in' =>
                'El turno seleccionado no es válido.',
            'capacity.required' =>
                'La capacidad es obligatoria.',
            'capacity.between' =>
                'La capacidad debe estar entre 1 y 500 alumnos.',
        ];
    }

    private function normalizeText(mixed $value): mixed
    {
        return is_string($value)
            ? preg_replace('/\s+/', ' ', trim($value))
            : $value;
    }

    private function normalizeUppercase(mixed $value): mixed
    {
        $normalized = $this->normalizeText($value);

        return is_string($normalized)
            ? mb_strtoupper($normalized)
            : $normalized;
    }

    private function normalizeLowercase(mixed $value): mixed
    {
        $normalized = $this->normalizeText($value);

        return is_string($normalized)
            ? mb_strtolower($normalized)
            : $normalized;
    }

    private function normalizeNullableText(mixed $value): mixed
    {
        $normalized = $this->normalizeText($value);

        return $normalized === ''
            ? null
            : $normalized;
    }
}