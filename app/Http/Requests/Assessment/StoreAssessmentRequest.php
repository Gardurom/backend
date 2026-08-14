<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use App\Models\GradingPeriod;
use App\Models\TeachingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('teaching_assignment_id')) {
            $values['teaching_assignment_id'] = trim(
                (string) $this->input(
                    'teaching_assignment_id'
                )
            );
        }

        if ($this->exists('grading_period_id')) {
            $values['grading_period_id'] = trim(
                (string) $this->input(
                    'grading_period_id'
                )
            );
        }

        if ($this->exists('name')) {
            $values['name'] = $this->normalizeRequiredText(
                $this->input('name')
            );
        }

        if ($this->exists('description')) {
            $values['description'] =
                $this->normalizeNullableText(
                    $this->input('description')
                );
        }

        if ($this->exists('type')) {
            $values['type'] = mb_strtolower(
                trim((string) $this->input('type'))
            );
        }

        if ($this->exists('status')) {
            $values['status'] = mb_strtolower(
                trim((string) $this->input('status'))
            );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        $assignmentId = (string) $this->input(
            'teaching_assignment_id'
        );

        $gradingPeriodId = (string) $this->input(
            'grading_period_id'
        );

        return [
            'teaching_assignment_id' => [
                'required',
                'uuid',
                Rule::exists(
                    'teaching_assignments',
                    'id'
                )->whereNull('deleted_at'),
            ],

            'grading_period_id' => [
                'required',
                'uuid',
                Rule::exists(
                    'grading_periods',
                    'id'
                ),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('assessments', 'name')
                    ->where(
                        fn ($query) => $query
                            ->where(
                                'teaching_assignment_id',
                                $assignmentId
                            )
                            ->where(
                                'grading_period_id',
                                $gradingPeriodId
                            )
                    ),
            ],

            'description' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'type' => [
                'required',
                'string',
                Rule::in([
                    'exam',
                    'quiz',
                    'homework',
                    'project',
                    'participation',
                    'practice',
                    'other',
                ]),
            ],

            'maximum_score' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:999999.99',
            ],

            'weight' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:100',
            ],

            'due_at' => [
                'nullable',
                'date',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'draft',
                    'published',
                    'closed',
                    'cancelled',
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateAcademicContext(
                    $validator
                );

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateTotalWeight(
                    $validator
                );
            },
        ];
    }

    public function messages(): array
    {
        return [
            'teaching_assignment_id.required' =>
                'La asignación docente es obligatoria.',

            'teaching_assignment_id.uuid' =>
                'El identificador de la asignación docente no es válido.',

            'teaching_assignment_id.exists' =>
                'La asignación docente no existe.',

            'grading_period_id.required' =>
                'El periodo de evaluación es obligatorio.',

            'grading_period_id.uuid' =>
                'El identificador del periodo de evaluación no es válido.',

            'grading_period_id.exists' =>
                'El periodo de evaluación no existe.',

            'name.required' =>
                'El nombre de la evaluación es obligatorio.',

            'name.unique' =>
                'Ya existe una evaluación con este nombre en la asignación y periodo seleccionados.',

            'type.required' =>
                'El tipo de evaluación es obligatorio.',

            'type.in' =>
                'El tipo de evaluación no es válido.',

            'maximum_score.required' =>
                'La puntuación máxima es obligatoria.',

            'maximum_score.numeric' =>
                'La puntuación máxima debe ser numérica.',

            'maximum_score.decimal' =>
                'La puntuación máxima puede tener hasta dos decimales.',

            'maximum_score.gt' =>
                'La puntuación máxima debe ser mayor que cero.',

            'maximum_score.max' =>
                'La puntuación máxima excede el valor permitido.',

            'weight.required' =>
                'La ponderación es obligatoria.',

            'weight.numeric' =>
                'La ponderación debe ser numérica.',

            'weight.decimal' =>
                'La ponderación puede tener hasta dos decimales.',

            'weight.gt' =>
                'La ponderación debe ser mayor que cero.',

            'weight.max' =>
                'La ponderación no puede ser mayor que 100.',

            'due_at.date' =>
                'La fecha límite no tiene un formato válido.',

            'status.in' =>
                'El estado de la evaluación no es válido.',
        ];
    }

    private function validateAcademicContext(
        Validator $validator
    ): void {
        $campusId = (string) $this->attributes->get(
            'campus_id'
        );

        if ($campusId === '') {
            $validator->errors()->add(
                'campus_id',
                'No se proporcionó un contexto de plantel válido.'
            );

            return;
        }

        $assignment = TeachingAssignment::query()
            ->with([
                'schoolGroup.schoolCycle:id,campus_id',
                'subject:id,campus_id',
                'teacher:id,campus_id',
            ])
            ->find(
                $this->input(
                    'teaching_assignment_id'
                )
            );

        if (! $assignment) {
            $validator->errors()->add(
                'teaching_assignment_id',
                'La asignación docente no existe.'
            );

            return;
        }

        $schoolCycle = $assignment
            ->schoolGroup
            ?->schoolCycle;

        if (
            ! $schoolCycle
            || $schoolCycle->campus_id !== $campusId
            || $assignment->subject?->campus_id
                !== $campusId
            || $assignment->teacher?->campus_id
                !== $campusId
        ) {
            $validator->errors()->add(
                'teaching_assignment_id',
                'La asignación docente no pertenece al plantel seleccionado.'
            );

            return;
        }

        $gradingPeriod = GradingPeriod::query()
            ->find(
                $this->input('grading_period_id')
            );

        if (! $gradingPeriod) {
            $validator->errors()->add(
                'grading_period_id',
                'El periodo de evaluación no existe.'
            );

            return;
        }

        if (
            $gradingPeriod->school_cycle_id
                !== $schoolCycle->id
        ) {
            $validator->errors()->add(
                'grading_period_id',
                'El periodo de evaluación no pertenece al ciclo escolar del grupo.'
            );
        }
    }

    private function validateTotalWeight(
        Validator $validator
    ): void {
        $status = (string) $this->input(
            'status',
            'draft'
        );

        /*
         * Una evaluación cancelada no participa en la
         * suma de ponderaciones del periodo.
         */
        if ($status === 'cancelled') {
            return;
        }

        $currentWeight = Assessment::query()
            ->where(
                'teaching_assignment_id',
                $this->input(
                    'teaching_assignment_id'
                )
            )
            ->where(
                'grading_period_id',
                $this->input('grading_period_id')
            )
            ->where(
                'status',
                '<>',
                'cancelled'
            )
            ->sum('weight');

        $requestedWeight = (float) $this->input(
            'weight'
        );

        if (
            (float) $currentWeight
                + $requestedWeight
                > 100.00001
        ) {
            $validator->errors()->add(
                'weight',
                'La suma de ponderaciones del periodo no puede superar 100.'
            );
        }
    }

    private function normalizeRequiredText(
        mixed $value
    ): mixed {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        );
    }

    private function normalizeNullableText(
        mixed $value
    ): mixed {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $normalized = preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        );

        return $normalized === ''
            ? null
            : $normalized;
    }
}