<?php

namespace App\Http\Requests\Geofence;

use App\Models\Geofence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGeofenceRequest extends FormRequest
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
                'sometimes',
                'required',
                'string',
                'max:150',
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
                    'polygon',
                    'circle',
                    'corridor',
                ]),
            ],

            'longitude' => [
                'sometimes',
                'required',
                'numeric',
                'between:-180,180',
            ],

            'latitude' => [
                'sometimes',
                'required',
                'numeric',
                'between:-90,90',
            ],

            'radius_meters' => [
                'sometimes',
                'required',
                'numeric',
                'min:1',
                'max:100000',
            ],

            'geometry' => [
                'sometimes',
                'required',
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
                'required',
                'array',
            ],

            'valid_from' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'valid_until' => [
                'sometimes',
                'nullable',
                'date',
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

                $geofence = $this->route('geofence');

                if (! $geofence instanceof Geofence) {
                    $validator->errors()->add(
                        'geofence',
                        'No se pudo determinar la geocerca que se desea actualizar.'
                    );

                    return;
                }

                $this->validateDetectionConfiguration(
                    validator: $validator,
                    geofence: $geofence,
                );

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateDates(
                    validator: $validator,
                    geofence: $geofence,
                );

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateSpatialUpdate(
                    validator: $validator,
                    geofence: $geofence,
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

            'longitude.required' =>
                'La longitud es obligatoria cuando se actualiza el centro de una geocerca circular.',

            'longitude.numeric' =>
                'La longitud debe ser numérica.',

            'longitude.between' =>
                'La longitud debe estar entre -180 y 180.',

            'latitude.required' =>
                'La latitud es obligatoria cuando se actualiza el centro de una geocerca circular.',

            'latitude.numeric' =>
                'La latitud debe ser numérica.',

            'latitude.between' =>
                'La latitud debe estar entre -90 y 90.',

            'radius_meters.required' =>
                'El radio es obligatorio cuando se actualiza la geometría que lo utiliza.',

            'radius_meters.numeric' =>
                'El radio debe ser numérico.',

            'radius_meters.min' =>
                'El radio debe ser de al menos 1 metro.',

            'radius_meters.max' =>
                'El radio no puede exceder 100000 metros.',

            'geometry.required' =>
                'La geometría no puede estar vacía cuando se proporciona.',

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

            'is_active.boolean' =>
                'is_active debe ser verdadero o falso.',
        ];
    }

    private function validateDetectionConfiguration(
        Validator $validator,
        Geofence $geofence,
    ): void {
        $detectEntry = $this->exists('detect_entry')
            ? $this->boolean('detect_entry')
            : (bool) $geofence->detect_entry;

        $detectExit = $this->exists('detect_exit')
            ? $this->boolean('detect_exit')
            : (bool) $geofence->detect_exit;

        if (! $detectEntry && ! $detectExit) {
            $validator->errors()->add(
                'detect_entry',
                'La geocerca debe detectar al menos entradas o salidas.'
            );
        }
    }

    private function validateDates(
        Validator $validator,
        Geofence $geofence,
    ): void {
        $validFrom = $this->exists('valid_from')
            ? $this->date('valid_from')
            : $geofence->valid_from;

        $validUntil = $this->exists('valid_until')
            ? $this->date('valid_until')
            : $geofence->valid_until;

        if (
            $validFrom !== null
            && $validUntil !== null
            && $validUntil->lt($validFrom)
        ) {
            $validator->errors()->add(
                'valid_until',
                'La fecha final no puede ser anterior a la fecha inicial.'
            );
        }
    }

    private function validateSpatialUpdate(
        Validator $validator,
        Geofence $geofence,
    ): void {
        $type = (string) $this->input(
            'type',
            $geofence->type
        );

        $typeChanged = $this->exists('type')
            && $type !== $geofence->type;

        $hasLongitude = $this->exists('longitude');
        $hasLatitude = $this->exists('latitude');
        $hasRadius = $this->exists('radius_meters');
        $hasGeometry = $this->exists('geometry');

        if ($type === 'circle') {
            if (
                $typeChanged
                || $hasLongitude
                || $hasLatitude
                || $hasRadius
            ) {
                if (! $hasLongitude) {
                    $validator->errors()->add(
                        'longitude',
                        'La longitud es obligatoria para definir una geocerca circular.'
                    );
                }

                if (! $hasLatitude) {
                    $validator->errors()->add(
                        'latitude',
                        'La latitud es obligatoria para definir una geocerca circular.'
                    );
                }

                if (! $hasRadius) {
                    $validator->errors()->add(
                        'radius_meters',
                        'El radio es obligatorio para definir una geocerca circular.'
                    );
                }
            }

            if ($hasGeometry) {
                $validator->errors()->add(
                    'geometry',
                    'Una geocerca circular se define con longitud, latitud y radio, no con GeoJSON.'
                );
            }

            return;
        }

        if ($hasLongitude || $hasLatitude) {
            $validator->errors()->add(
                'longitude',
                'Longitud y latitud solo se utilizan para geocercas circulares.'
            );
        }

        if ($type === 'polygon') {
            if ($typeChanged && ! $hasGeometry) {
                $validator->errors()->add(
                    'geometry',
                    'La geometría es obligatoria al cambiar una geocerca a polygon.'
                );

                return;
            }

            if (! $hasGeometry) {
                return;
            }

            $geometryType = (string) $this->input(
                'geometry.type'
            );

            if (! in_array(
                $geometryType,
                [
                    'Polygon',
                    'MultiPolygon',
                ],
                true
            )) {
                $validator->errors()->add(
                    'geometry.type',
                    'Una geocerca polygon requiere geometría Polygon o MultiPolygon.'
                );
            }

            if ($hasRadius) {
                $validator->errors()->add(
                    'radius_meters',
                    'Una geocerca polygon no utiliza radio.'
                );
            }

            return;
        }

        if ($type === 'corridor') {
            if (
                ($typeChanged || $hasGeometry || $hasRadius)
                && ! $hasGeometry
            ) {
                $validator->errors()->add(
                    'geometry',
                    'La geometría es obligatoria para definir un corredor.'
                );
            }

            if (
                ($typeChanged || $hasGeometry || $hasRadius)
                && ! $hasRadius
            ) {
                $validator->errors()->add(
                    'radius_meters',
                    'El radio es obligatorio para definir el ancho del corredor.'
                );
            }

            if (! $hasGeometry) {
                return;
            }

            $geometryType = (string) $this->input(
                'geometry.type'
            );

            if (! in_array(
                $geometryType,
                [
                    'LineString',
                    'MultiLineString',
                ],
                true
            )) {
                $validator->errors()->add(
                    'geometry.type',
                    'Una geocerca corridor requiere geometría LineString o MultiLineString.'
                );
            }
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