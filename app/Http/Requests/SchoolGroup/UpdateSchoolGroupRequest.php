<?php

namespace App\Http\Requests\SchoolGroup;

use App\Models\SchoolGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSchoolGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('grade_level')) {
            $values['grade_level'] = $this->normalizeText(
                $this->input('grade_level')
            );
        }

        if ($this->exists('section')) {
            $values['section'] = $this->normalizeUppercase(
                $this->input('section')
            );
        }

        if ($this->exists('shift')) {
            $values['shift'] = $this->normalizeLowercase(
                $this->input('shift')
            );
        }

        if ($this->exists('classroom')) {
            $values['classroom'] = $this->normalizeNullableText(
                $this->input('classroom')
            );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        $campusId = (string) $this->attributes->get(
            'campus_id'
        );

        return [
            'school_cycle_id' => [
                'sometimes',
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
                'sometimes',
                'required',
                'string',
                'max:30',
            ],
            'section' => [
                'sometimes',
                'required',
                'string',
                'max:20',
            ],
            'shift' => [
                'sometimes',
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
                'sometimes',
                'required',
                'integer',
                'between:1,500',
            ],
            'classroom' => [
                'sometimes',
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

            /** @var SchoolGroup|null $schoolGroup */
            $schoolGroup = $this->route('schoolGroup')
                ?? $this->route('school_group');

            if (! $schoolGroup instanceof SchoolGroup) {
                return;
            }

            $schoolCycleId = $this->input(
                'school_cycle_id',
                $schoolGroup->school_cycle_id
            );

            $gradeLevel = $this->input(
                'grade_level',
                $schoolGroup->grade_level
            );

            $section = $this->input(
                'section',
                $schoolGroup->section
            );

            $shift = $this->input(
                'shift',
                $schoolGroup->shift
            );

            $exists = SchoolGroup::query()
                ->withTrashed()
                ->where('school_cycle_id', $schoolCycleId)
                ->where('grade_level', $gradeLevel)
                ->where('section', $section)
                ->where('shift', $shift)
                ->whereKeyNot($schoolGroup->getKey())
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'section',
                    'Ya existe otro grupo con el mismo ciclo, grado, sección y turno.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'school_cycle_id.exists' =>
                'El ciclo escolar no existe o no pertenece al plantel seleccionado.',
            'grade_level.required' =>
                'El grado escolar no puede quedar vacío.',
            'section.required' =>
                'La sección no puede quedar vacía.',
            'shift.in' =>
                'El turno seleccionado no es válido.',
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