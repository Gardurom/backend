<?php

namespace App\Http\Requests\Geofence;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGeofenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('name')) {
            $values['name'] = $this->normalizeRequiredText(
                $this->input('name')
            );
        }

        if ($this->exists('description')) {
            $values['description'] = $this->normalizeNullableText(
                $this->input('description')
            );
        }

        if ($this->exists('type')) {
            $values['type'] = mb_strtolower(
                trim((string) $this->input('type'))
            );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:150',
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
                    'polygon',
                    'circle',
                    'corridor',
                ]),
            ],

            'longitude' => [
                'required_if:type,circle',
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'latitude' => [
                'required_if:type,circle',
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'radius_meters' => [
                'required_if:type,circle,corridor',
                'nullable',
                'numeric',
                'min:1',
                'max:100000',
            ],

            'geometry' => [
                'required_if:type,polygon,corridor',
                'nullable',
                'array',
            ],

            'geometry.type' => [
                'required_with:geometry',
                'string',
            ],

            'geometry.coordinates' => [
                'required_with:geometry',
                'array',
                'min:1',
            ],

            'detect_entry' => [
                'sometimes',
                'required',
                'boolean',
            ],

            'detect_exit' => [
                'sometimes',
                'required',
                'boolean',
            ],

            'schedule' => [
                'sometimes',
                'array',
            ],

            'valid_from' => [
                'nullable',
                'date',
            ],

            'valid_until' => [
                'nullable',
                'date',
                'after_or_equal:valid_from',
            ],

            'is_active' => [
                'sometimes',
                'required',
                'boolean',
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

                $this->validateDetectionConfiguration(
                    $validator
                );

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateGeometryType(
                    $validator
                );
            },
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' =>
                'El nombre de la geocerca es obligatorio.',

            'name.max' =>
                'El nombre de la geocerca no puede exceder 150 caracteres.',

            'description.max' =>
                'La descripción no puede exceder 5000 caracteres.',

            'type.required' =>
                'El tipo de geocerca es obligatorio.',

            'type.in' =>
                'El tipo de geocerca debe ser polygon, circle o corridor.',

            'longitude.required_if' =>
                'La longitud es obligatoria para una geocerca circular.',

            'longitude.numeric' =>
                'La longitud debe ser numérica.',

            'longitude.between' =>
                'La longitud debe estar entre -180 y 180.',

            'latitude.required_if' =>
                'La latitud es obligatoria para una geocerca circular.',

            'latitude.numeric' =>
                'La latitud debe ser numérica.',

            'latitude.between' =>
                'La latitud debe estar entre -90 y 90.',

            'radius_meters.required_if' =>
                'El radio es obligatorio para geocercas circulares y corredores.',

            'radius_meters.numeric' =>
                'El radio debe ser numérico.',

            'radius_meters.min' =>
                'El radio debe ser de al menos 1 metro.',

            'radius_meters.max' =>
                'El radio no puede exceder 100000 metros.',

            'geometry.required_if' =>
                'La geometría es obligatoria para polígonos y corredores.',

            'geometry.array' =>
                'La geometría debe enviarse como un objeto GeoJSON válido.',

            'geometry.type.required_with' =>
                'El tipo de geometría GeoJSON es obligatorio.',

            'geometry.coordinates.required_with' =>
                'Las coordenadas GeoJSON son obligatorias.',

            'geometry.coordinates.array' =>
                'Las coordenadas GeoJSON deben ser un arreglo.',

            'detect_entry.boolean' =>
                'detect_entry debe ser verdadero o falso.',

            'detect_exit.boolean' =>
                'detect_exit debe ser verdadero o falso.',

            'schedule.array' =>
                'La configuración de horario debe ser un objeto JSON.',

            'valid_from.date' =>
                'La fecha inicial no tiene un formato válido.',

            'valid_until.date' =>
                'La fecha final no tiene un formato válido.',

            'valid_until.after_or_equal' =>
                'La fecha final no puede ser anterior a la fecha inicial.',

            'is_active.boolean' =>
                'is_active debe ser verdadero o falso.',
        ];
    }

    private function validateDetectionConfiguration(
        Validator $validator
    ): void {
        $detectEntry = $this->boolean(
            'detect_entry',
            true
        );

        $detectExit = $this->boolean(
            'detect_exit',
            true
        );

        if (! $detectEntry && ! $detectExit) {
            $validator->errors()->add(
                'detect_entry',
                'La geocerca debe detectar al menos entradas o salidas.'
            );
        }
    }

    private function validateGeometryType(
        Validator $validator
    ): void {
        $type = (string) $this->input('type');

        if ($type === 'circle') {
            return;
        }

        $geometryType = (string) $this->input(
            'geometry.type'
        );

        if (
            $type === 'polygon'
            && ! in_array(
                $geometryType,
                [
                    'Polygon',
                    'MultiPolygon',
                ],
                true
            )
        ) {
            $validator->errors()->add(
                'geometry.type',
                'Una geocerca polygon requiere geometría Polygon o MultiPolygon.'
            );

            return;
        }

        if (
            $type === 'corridor'
            && ! in_array(
                $geometryType,
                [
                    'LineString',
                    'MultiLineString',
                ],
                true
            )
        ) {
            $validator->errors()->add(
                'geometry.type',
                'Una geocerca corridor requiere geometría LineString o MultiLineString.'
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