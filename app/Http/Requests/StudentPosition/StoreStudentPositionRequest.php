<?php

namespace App\Http\Requests\StudentPosition;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => [
                'nullable',
                'uuid',
            ],
            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],
            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],
            'captured_at' => [
                'required',
                'date',
            ],
            'accuracy_meters' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'altitude_meters' => [
                'nullable',
                'numeric',
            ],
            'speed_meters_second' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'heading_degrees' => [
                'nullable',
                'numeric',
                'min:0',
                'lt:360',
            ],
            'source' => [
                'sometimes',
                'string',
                'in:gps,mobile,manual,import,device',
            ],
            'device_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'metadata' => [
                'sometimes',
                'array',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'position_id.uuid' =>
                'El identificador de posición debe ser un UUID válido.',

            'longitude.required' =>
                'La longitud es obligatoria.',
            'longitude.numeric' =>
                'La longitud debe ser numérica.',
            'longitude.between' =>
                'La longitud debe estar entre -180 y 180.',

            'latitude.required' =>
                'La latitud es obligatoria.',
            'latitude.numeric' =>
                'La latitud debe ser numérica.',
            'latitude.between' =>
                'La latitud debe estar entre -90 y 90.',

            'captured_at.required' =>
                'La fecha de captura es obligatoria.',
            'captured_at.date' =>
                'La fecha de captura no es válida.',

            'accuracy_meters.numeric' =>
                'La precisión debe ser numérica.',
            'accuracy_meters.min' =>
                'La precisión no puede ser negativa.',

            'altitude_meters.numeric' =>
                'La altitud debe ser numérica.',

            'speed_meters_second.numeric' =>
                'La velocidad debe ser numérica.',
            'speed_meters_second.min' =>
                'La velocidad no puede ser negativa.',

            'heading_degrees.numeric' =>
                'El rumbo debe ser numérico.',
            'heading_degrees.min' =>
                'El rumbo no puede ser negativo.',
            'heading_degrees.lt' =>
                'El rumbo debe ser menor que 360 grados.',

            'source.in' =>
                'La fuente de ubicación no es válida.',

            'device_reference.string' =>
                'La referencia del dispositivo debe ser texto.',
            'device_reference.max' =>
                'La referencia del dispositivo no puede exceder 255 caracteres.',

            'metadata.array' =>
                'Los metadatos deben ser un objeto válido.',
        ];
    }
}