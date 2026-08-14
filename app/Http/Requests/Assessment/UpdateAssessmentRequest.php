<?php

namespace App\Http\Requests\Assessment;

use App\Models\Assessment;
use App\Models\GradingPeriod;
use App\Models\TeachingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach (
            [
                'teaching_assignment_id',
                'grading_period_id',
            ] as $field
        ) {
            if ($this->exists($field)) {
                $values[$field] = trim(
                    (string) $this->input($field)
                );
            }
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
        /** @var Assessment|null $assessment */
        $assessment = $this->route('assessment');

        if (! $assessment instanceof Assessment) {
            return [];
        }

        $assignmentId = (string) $this->input(
            'teaching_assignment_id',
            $assessment->teaching_assignment_id
        );

        $gradingPeriodId = (string) $this->input(
            'grading_period_id',
            $assessment->grading_period_id
        );

        return [
            'teaching_assignment_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists(
                    'teaching_assignments',
                    'id'
                )->whereNull('deleted_at'),
            ],

            'grading_period_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists(
                    'grading_periods',
                    'id'
                ),
            ],

            'name' => [
                'sometimes',
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
                    )
                    ->ignore($assessment->id),
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'type' => [
                'sometimes',
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
                'sometimes',
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:999999.99',
            ],

            'weight' => [
                'sometimes',
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:100',
            ],

            'due_at' => [
                'sometimes',
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

                /** @var Assessment|null $assessment */
                $assessment = $this->route(
                    'assessment'
                );

                if (! $assessment instanceof Assessment) {
                    $validator->errors()->add(
                        'assessment',
                        'La evaluación no es válida.'
                    );

                    return;
                }

                $this->validateAcademicContext(
                    $validator,
                    $assessment
                );

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateTotalWeight(
                    $validator,
                    $assessment
                );
            },
        ];
    }

    public function messages(): array
    {
        return [
            'teaching_assignment_id.exists' =>
                'La asignación docente no existe.',

            'grading_period_id.exists' =>
                'El periodo de evaluación no existe.',

            'name.unique' =>
                'Ya existe una evaluación con este nombre en la asignación y periodo seleccionados.',

            'type.in' =>
                'El tipo de evaluación no es válido.',

            'maximum_score.gt' =>
                'La puntuación máxima debe ser mayor que cero.',

            'weight.gt' =>
                'La ponderación debe ser mayor que cero.',

            'weight.max' =>
                'La ponderación no puede ser mayor que 100.',

            'status.in' =>
                'El estado de la evaluación no es válido.',
        ];
    }

    private function validateAcademicContext(
        Validator $validator,
        Assessment $assessment
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

        $assignmentId = $this->input(
            'teaching_assignment_id',
            $assessment->teaching_assignment_id
        );

        $gradingPeriodId = $this->input(
            'grading_period_id',
            $assessment->grading_period_id
        );

        $assignment = TeachingAssignment::query()
            ->with([
                'schoolGroup.schoolCycle:id,campus_id',
                'subject:id,campus_id',
                'teacher:id,campus_id',
            ])
            ->find($assignmentId);

        $gradingPeriod = GradingPeriod::query()
            ->find($gradingPeriodId);

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
        }

        if (! $gradingPeriod) {
            $validator->errors()->add(
                'grading_period_id',
                'El periodo de evaluación no existe.'
            );

            return;
        }

        if (
            $schoolCycle
            && $gradingPeriod->school_cycle_id
                !== $schoolCycle->id
        ) {
            $validator->errors()->add(
                'grading_period_id',
                'El periodo de evaluación no pertenece al ciclo escolar del grupo.'
            );
        }
    }

    private function validateTotalWeight(
        Validator $validator,
        Assessment $assessment
    ): void {
        $assignmentId = $this->input(
            'teaching_assignment_id',
            $assessment->teaching_assignment_id
        );

        $gradingPeriodId = $this->input(
            'grading_period_id',
            $assessment->grading_period_id
        );

        $status = $this->input(
            'status',
            $assessment->status
        );

        $requestedWeight = (float) $this->input(
            'weight',
            $assessment->weight
        );

        $currentWeight = Assessment::query()
            ->where(
                'teaching_assignment_id',
                $assignmentId
            )
            ->where(
                'grading_period_id',
                $gradingPeriodId
            )
            ->whereKeyNot($assessment->id)
            ->whereNot('status', 'cancelled')
            ->sum('weight');

        if ($status === 'cancelled') {
            $requestedWeight = 0;
        }

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

        $value = preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        );

        return $value === ''
            ? null
            : $value;
    }
}