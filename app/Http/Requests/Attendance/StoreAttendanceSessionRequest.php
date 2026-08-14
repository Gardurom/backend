<?php

namespace App\Http\Requests\Attendance;

use App\Models\SchoolGroup;
use App\Models\Teacher;
use App\Models\TeachingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttendanceSessionRequest extends FormRequest
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
                'school_group_id',
                'teaching_assignment_id',
                'recorded_by_teacher_id',
            ] as $field
        ) {
            if ($this->exists($field)) {
                $value = $this->input($field);

                $values[$field] =
                    $value === null
                    || trim((string) $value) === ''
                        ? null
                        : trim((string) $value);
            }
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

        if ($this->exists('notes')) {
            $values['notes'] =
                $this->normalizeNullableText(
                    $this->input('notes')
                );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'school_group_id' => [
                'required',
                'uuid',
                Rule::exists('school_groups', 'id')
                    ->whereNull('deleted_at'),
            ],

            'teaching_assignment_id' => [
                'nullable',
                'uuid',
                Rule::exists(
                    'teaching_assignments',
                    'id'
                )->whereNull('deleted_at'),
            ],

            'recorded_by_teacher_id' => [
                'nullable',
                'uuid',
                Rule::exists('teachers', 'id')
                    ->whereNull('deleted_at'),
            ],

            'held_on' => [
                'required',
                'date_format:Y-m-d',
            ],

            'starts_at' => [
                'nullable',
                'date_format:H:i',
            ],

            'ends_at' => [
                'nullable',
                'date_format:H:i',
            ],

            'type' => [
                'required',
                'string',
                Rule::in([
                    'daily',
                    'class',
                ]),
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'scheduled',
                    'open',
                    'closed',
                    'cancelled',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:5000',
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

                $this->validateTimes(
                    $validator
                );
            },
        ];
    }

    public function messages(): array
    {
        return [
            'school_group_id.required' =>
                'El grupo escolar es obligatorio.',

            'school_group_id.exists' =>
                'El grupo escolar no existe.',

            'teaching_assignment_id.exists' =>
                'La asignación docente no existe.',

            'recorded_by_teacher_id.exists' =>
                'El profesor no existe.',

            'held_on.required' =>
                'La fecha de asistencia es obligatoria.',

            'held_on.date_format' =>
                'La fecha debe tener el formato YYYY-MM-DD.',

            'starts_at.date_format' =>
                'La hora inicial debe tener el formato HH:MM.',

            'ends_at.date_format' =>
                'La hora final debe tener el formato HH:MM.',

            'type.in' =>
                'El tipo de sesión no es válido.',

            'status.in' =>
                'El estado de la sesión no es válido.',
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

        $group = SchoolGroup::query()
            ->with('schoolCycle:id,campus_id')
            ->find(
                $this->input('school_group_id')
            );

        if (
            ! $group
            || ! $group->schoolCycle
            || $group->schoolCycle->campus_id
                !== $campusId
        ) {
            $validator->errors()->add(
                'school_group_id',
                'El grupo no pertenece al plantel seleccionado.'
            );

            return;
        }

        if (! $group->is_active) {
            $validator->errors()->add(
                'school_group_id',
                'El grupo seleccionado está inactivo.'
            );
        }

        $type = (string) $this->input('type');

        $assignmentId = $this->input(
            'teaching_assignment_id'
        );

        if (
            $type === 'class'
            && ! $assignmentId
        ) {
            $validator->errors()->add(
                'teaching_assignment_id',
                'Una sesión de clase requiere una asignación docente.'
            );

            return;
        }

        if (
            $type === 'daily'
            && $assignmentId
        ) {
            $validator->errors()->add(
                'teaching_assignment_id',
                'Una sesión diaria no debe tener asignación docente.'
            );

            return;
        }

        $assignment = null;

        if ($assignmentId) {
            $assignment = TeachingAssignment::query()
                ->with([
                    'subject:id,campus_id',
                    'teacher:id,campus_id,status',
                ])
                ->find($assignmentId);

            if (
                ! $assignment
                || $assignment->school_group_id
                    !== $group->id
                || $assignment->subject?->campus_id
                    !== $campusId
                || $assignment->teacher?->campus_id
                    !== $campusId
            ) {
                $validator->errors()->add(
                    'teaching_assignment_id',
                    'La asignación docente no pertenece al grupo y plantel seleccionados.'
                );

                return;
            }
        }

        $teacherId = $this->input(
            'recorded_by_teacher_id'
        );

        if ($teacherId) {
            $teacher = Teacher::query()
                ->find($teacherId);

            if (
                ! $teacher
                || $teacher->campus_id !== $campusId
            ) {
                $validator->errors()->add(
                    'recorded_by_teacher_id',
                    'El profesor no pertenece al plantel seleccionado.'
                );

                return;
            }

            if ($teacher->status !== 'active') {
                $validator->errors()->add(
                    'recorded_by_teacher_id',
                    'El profesor seleccionado no está activo.'
                );
            }
        }

        if (
            $assignment
            && $teacherId
            && $assignment->teacher_id !== $teacherId
        ) {
            $validator->errors()->add(
                'recorded_by_teacher_id',
                'El profesor debe coincidir con la asignación docente.'
            );
        }
    }

    private function validateTimes(
        Validator $validator
    ): void {
        if (
            $validator->errors()->has('starts_at')
            || $validator->errors()->has('ends_at')
        ) {
            return;
        }

        $startsAt = $this->input('starts_at');
        $endsAt = $this->input('ends_at');

        if (! $startsAt || ! $endsAt) {
            return;
        }

        if ($endsAt <= $startsAt) {
            $validator->errors()->add(
                'ends_at',
                'La hora final debe ser posterior a la hora inicial.'
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