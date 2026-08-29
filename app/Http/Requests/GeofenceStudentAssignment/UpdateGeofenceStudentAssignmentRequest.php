<?php

namespace App\Http\Requests\GeofenceStudentAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeofenceStudentAssignmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            return;
        }

        $value = $this->input('is_active');

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
                'is_active' => $normalized,
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
            'consent_reference' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'starts_at' => [
                'sometimes',
                'required',
                'date',
            ],
            'ends_at' => [
                'sometimes',
                'nullable',
                'date',
            ],
            'notification_settings' => [
                'sometimes',
                'array',
            ],
            'notification_settings.notify_entry' => [
                'sometimes',
                'boolean',
            ],
            'notification_settings.notify_exit' => [
                'sometimes',
                'boolean',
            ],
            'notification_settings.channels' => [
                'sometimes',
                'array',
            ],
            'notification_settings.channels.*' => [
                'string',
                Rule::in([
                    'database',
                    'email',
                ]),
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'consent_reference.required' =>
                'La referencia de autorización no puede estar vacía.',

            'consent_reference.string' =>
                'La referencia de autorización debe ser texto.',

            'consent_reference.max' =>
                'La referencia de autorización no puede exceder 255 caracteres.',

            'starts_at.required' =>
                'La fecha inicial no puede estar vacía.',

            'starts_at.date' =>
                'La fecha inicial no es válida.',

            'ends_at.date' =>
                'La fecha final no es válida.',

            'notification_settings.array' =>
                'La configuración de notificaciones debe ser un objeto.',

            'notification_settings.notify_entry.boolean' =>
                'La opción notify_entry debe ser verdadera o falsa.',

            'notification_settings.notify_exit.boolean' =>
                'La opción notify_exit debe ser verdadera o falsa.',

            'notification_settings.channels.array' =>
                'Los canales de notificación deben ser una lista.',

            'notification_settings.channels.*.string' =>
                'Cada canal de notificación debe ser texto.',

            'notification_settings.channels.*.in' =>
                'El canal de notificación seleccionado no es válido.',

            'is_active.boolean' =>
                'El estado de la asignación debe ser verdadero o falso.',
        ];
    }
}