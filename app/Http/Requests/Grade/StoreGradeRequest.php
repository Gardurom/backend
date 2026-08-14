<?php

namespace App\Http\Requests\Grade;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('assessment_id')) {
            $values['assessment_id'] = trim(
                (string) $this->input('assessment_id')
            );
        }

        if ($this->exists('enrollment_id')) {
            $values['enrollment_id'] = trim(
                (string) $this->input('enrollment_id')
            );
        }

        if ($this->exists('feedback')) {
            $values['feedback'] =
                $this->normalizeNullableText(
                    $this->input('feedback')
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
        $assessmentId = (string) $this->input(
            'assessment_id'
        );

        return [
            'assessment_id' => [
                'required',
                'uuid',
                Rule::exists('assessments', 'id')
                    ->whereNull('deleted_at'),
            ],

            'enrollment_id' => [
                'required',
                'uuid',
                Rule::exists('enrollments', 'id')
                    ->whereNull('deleted_at'),

                Rule::unique('grades', 'enrollment_id')
                    ->where(
                        fn ($query) => $query->where(
                            'assessment_id',
                            $assessmentId
                        )
                    ),
            ],

            'score' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'gte:0',
            ],

            'feedback' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'graded_at' => [
                'nullable',
                'date',
            ],

            'status' => [
                'required',
                'string',
                Rule::in([
                    'pending',
                    'graded',
                    'exempt',
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

                $assessment = Assessment::query()
                    ->with([
                        'teachingAssignment.schoolGroup.schoolCycle',
                        'teachingAssignment.subject',
                        'teachingAssignment.teacher',
                        'gradingPeriod',
                    ])
                    ->find(
                        $this->input('assessment_id')
                    );

                $enrollment = Enrollment::query()
                    ->with([
                        'schoolGroup.schoolCycle',
                        'student',
                    ])
                    ->find(
                        $this->input('enrollment_id')
                    );

                $this->validateAcademicContext(
                    $validator,
                    $assessment,
                    $enrollment
                );

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateScoreAndStatus(
                    $validator,
                    $assessment
                );
            },
        ];
    }

    public function messages(): array
    {
        return [
            'assessment_id.required' =>
                'La evaluación es obligatoria.',

            'assessment_id.exists' =>
                'La evaluación no existe.',

            'enrollment_id.required' =>
                'La inscripción es obligatoria.',

            'enrollment_id.exists' =>
                'La inscripción no existe.',

            'enrollment_id.unique' =>
                'La inscripción ya tiene una calificación para esta evaluación.',

            'score.numeric' =>
                'La puntuación debe ser numérica.',

            'score.decimal' =>
                'La puntuación puede tener hasta dos decimales.',

            'score.gte' =>
                'La puntuación no puede ser negativa.',

            'status.in' =>
                'El estado de la calificación no es válido.',
        ];
    }

    private function validateAcademicContext(
        Validator $validator,
        ?Assessment $assessment,
        ?Enrollment $enrollment
    ): void {
        if (! $assessment) {
            $validator->errors()->add(
                'assessment_id',
                'La evaluación no existe.'
            );

            return;
        }

        if (! $enrollment) {
            $validator->errors()->add(
                'enrollment_id',
                'La inscripción no existe.'
            );

            return;
        }

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

        $assignment = $assessment
            ->teachingAssignment;

        $schoolCycle = $assignment
            ?->schoolGroup
            ?->schoolCycle;

        if (
            ! $assignment
            || ! $schoolCycle
            || $schoolCycle->campus_id !== $campusId
            || $assignment->subject?->campus_id
                !== $campusId
            || $assignment->teacher?->campus_id
                !== $campusId
        ) {
            $validator->errors()->add(
                'assessment_id',
                'La evaluación no pertenece al plantel seleccionado.'
            );

            return;
        }

        if (
            $enrollment->school_group_id
                !== $assignment->school_group_id
        ) {
            $validator->errors()->add(
                'enrollment_id',
                'El alumno no está inscrito en el grupo de esta evaluación.'
            );
        }

        if (
            $enrollment->school_cycle_id
                !== $schoolCycle->id
        ) {
            $validator->errors()->add(
                'enrollment_id',
                'La inscripción no pertenece al ciclo escolar de la evaluación.'
            );
        }

        if (
            $assessment->gradingPeriod
                ?->school_cycle_id
                !== $enrollment->school_cycle_id
        ) {
            $validator->errors()->add(
                'enrollment_id',
                'El periodo de la evaluación no coincide con el ciclo de la inscripción.'
            );
        }

        if (
            ! in_array(
                $enrollment->status,
                ['active', 'completed'],
                true
            )
        ) {
            $validator->errors()->add(
                'enrollment_id',
                'La inscripción no está habilitada para recibir calificaciones.'
            );
        }

        if (
            in_array(
                $assessment->status,
                ['cancelled'],
                true
            )
        ) {
            $validator->errors()->add(
                'assessment_id',
                'No se puede calificar una evaluación cancelada.'
            );
        }
    }

    private function validateScoreAndStatus(
        Validator $validator,
        Assessment $assessment
    ): void {
        $status = (string) $this->input('status');
        $score = $this->input('score');

        if ($status === 'graded' && $score === null) {
            $validator->errors()->add(
                'score',
                'La puntuación es obligatoria cuando el estado es graded.'
            );

            return;
        }

        if ($status !== 'graded' && $score !== null) {
            $validator->errors()->add(
                'score',
                'La puntuación debe ser nula cuando el estado no es graded.'
            );

            return;
        }

        if (
            $score !== null
            && (float) $score
                > (float) $assessment->maximum_score
        ) {
            $validator->errors()->add(
                'score',
                'La puntuación no puede superar la puntuación máxima de la evaluación.'
            );
        }
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