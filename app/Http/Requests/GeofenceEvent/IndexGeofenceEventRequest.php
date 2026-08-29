<?php

namespace App\Http\Requests\GeofenceEvent;

use Illuminate\Foundation\Http\FormRequest;

class IndexGeofenceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'geofence_id' => [
                'nullable',
                'uuid',
            ],

            'student_id' => [
                'nullable',
                'uuid',
            ],

            'event_type' => [
                'nullable',
                'string',
                'in:entry,exit',
            ],

            'from' => [
                'nullable',
                'date',
            ],

            'to' => [
                'nullable',
                'date',
                'after_or_equal:from',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'geofence_id.uuid' =>
                'El identificador de la geocerca debe ser un UUID válido.',

            'student_id.uuid' =>
                'El identificador del alumno debe ser un UUID válido.',

            'event_type.string' =>
                'El tipo de evento debe ser una cadena de texto.',

            'event_type.in' =>
                'El tipo de evento debe ser entry o exit.',

            'from.date' =>
                'La fecha inicial no tiene un formato válido.',

            'to.date' =>
                'La fecha final no tiene un formato válido.',

            'to.after_or_equal' =>
                'La fecha final debe ser igual o posterior a la fecha inicial.',

            'limit.integer' =>
                'El límite debe ser un número entero.',

            'limit.min' =>
                'El límite debe ser al menos 1.',

            'limit.max' =>
                'El límite no puede ser mayor a 500.',
        ];
    }
}