<?php

namespace App\Http\Requests\GeofenceStudentAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGeofenceStudentAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => [
                'required',
                'uuid',
            ],
            'consent_reference' => [
                'required',
                'string',
                'max:255',
            ],
            'starts_at' => [
                'required',
                'date',
            ],
            'ends_at' => [
                'nullable',
                'date',
                'after_or_equal:starts_at',
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
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'El alumno es obligatorio.',
            'student_id.uuid' => 'El identificador del alumno debe ser un UUID válido.',

            'consent_reference.required' => 'La referencia de autorización es obligatoria.',
            'consent_reference.string' => 'La referencia de autorización debe ser texto.',
            'consent_reference.max' => 'La referencia de autorización no puede exceder 255 caracteres.',

            'starts_at.required' => 'La fecha inicial es obligatoria.',
            'starts_at.date' => 'La fecha inicial no es válida.',

            'ends_at.date' => 'La fecha final no es válida.',
            'ends_at.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',

            'notification_settings.array' => 'La configuración de notificaciones debe ser un objeto.',
            'notification_settings.notify_entry.boolean' => 'La opción notify_entry debe ser verdadera o falsa.',
            'notification_settings.notify_exit.boolean' => 'La opción notify_exit debe ser verdadera o falsa.',
            'notification_settings.channels.array' => 'Los canales de notificación deben ser una lista.',
            'notification_settings.channels.*.string' => 'Cada canal de notificación debe ser texto.',
            'notification_settings.channels.*.in' => 'El canal de notificación seleccionado no es válido.',
        ];
    }
}