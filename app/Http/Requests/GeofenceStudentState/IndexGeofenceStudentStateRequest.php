<?php

namespace App\Http\Requests\GeofenceStudentState;

use Illuminate\Foundation\Http\FormRequest;

class IndexGeofenceStudentStateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_inside')) {
            return;
        }

        $value = $this->query('is_inside');

        if (is_bool($value)) {
            return;
        }

        $normalized = filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($normalized !== null) {
            $this->merge([
                'is_inside' => $normalized,
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'uuid'],
            'is_inside' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.uuid' =>
                'El identificador del alumno debe ser un UUID válido.',

            'is_inside.boolean' =>
                'El estado de ubicación debe ser verdadero o falso.',

            'limit.integer' =>
                'El límite debe ser un número entero.',

            'limit.min' =>
                'El límite debe ser al menos 1.',

            'limit.max' =>
                'El límite no puede ser mayor a 500.',
        ];
    }
}
