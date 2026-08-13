<?php

namespace App\Http\Requests\TeachingAssignment;

use App\Models\SchoolGroup;
use App\Models\Subject;
use App\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTeachingAssignmentRequest extends FormRequest
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
                'subject_id',
                'teacher_id',
            ] as $field
        ) {
            if ($this->exists($field)) {
                $values[$field] = trim(
                    (string) $this->input($field)
                );
            }
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
        return [
            'school_group_id' => [
                'required',
                'uuid',
                Rule::exists('school_groups', 'id')
                    ->whereNull('deleted_at'),
            ],

            'subject_id' => [
                'required',
                'uuid',
                Rule::exists('subjects', 'id')
                    ->whereNull('deleted_at'),
            ],

            'teacher_id' => [
                'required',
                'uuid',
                Rule::exists('teachers', 'id')
                    ->whereNull('deleted_at'),
            ],

            'starts_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'ends_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'active',
                    'completed',
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
                    ->find($this->input('school_group_id'));

                $subject = Subject::query()->find(
                    $this->input('subject_id')
                );

                $teacher = Teacher::query()->find(
                    $this->input('teacher_id')
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
                }

                if (
                    ! $subject
                    || $subject->campus_id !== $campusId
                ) {
                    $validator->errors()->add(
                        'subject_id',
                        'La materia no pertenece al plantel seleccionado.'
                    );
                }

                if (
                    ! $teacher
                    || $teacher->campus_id !== $campusId
                ) {
                    $validator->errors()->add(
                        'teacher_id',
                        'El profesor no pertenece al plantel seleccionado.'
                    );
                }

                if ($subject && ! $subject->is_active) {
                    $validator->errors()->add(
                        'subject_id',
                        'La materia seleccionada está inactiva.'
                    );
                }

                if (
                    $teacher
                    && $teacher->status !== 'active'
                ) {
                    $validator->errors()->add(
                        'teacher_id',
                        'El profesor seleccionado no está activo.'
                    );
                }

                if ($group && ! $group->is_active) {
                    $validator->errors()->add(
                        'school_group_id',
                        'El grupo seleccionado está inactivo.'
                    );
                }

                $this->validateDates($validator);
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

            'subject_id.required' =>
                'La materia es obligatoria.',

            'subject_id.exists' =>
                'La materia no existe.',

            'teacher_id.required' =>
                'El profesor es obligatorio.',

            'teacher_id.exists' =>
                'El profesor no existe.',

            'status.in' =>
                'El estado de la asignación no es válido.',
        ];
    }

    private function validateDates(
        Validator $validator
    ): void {
        if (
            $validator->errors()->has('starts_on')
            || $validator->errors()->has('ends_on')
        ) {
            return;
        }

        $startsOn = $this->input('starts_on');
        $endsOn = $this->input('ends_on');

        if (! $startsOn || ! $endsOn) {
            return;
        }

        if (
            Carbon::parse($endsOn)
                ->lt(Carbon::parse($startsOn))
        ) {
            $validator->errors()->add(
                'ends_on',
                'La fecha final no puede ser anterior a la fecha inicial.'
            );
        }
    }
}