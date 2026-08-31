<?php

namespace App\Http\Requests\Locality;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocalityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('official_code')) {
            $this->merge([
                'official_code' => trim(
                    (string) $this->input('official_code')
                ),
            ]);
        }

        if ($this->has('name')) {
            $this->merge([
                'name' => trim(
                    (string) $this->input('name')
                ),
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
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('localities', 'id'),
            ],

            'official_code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('localities', 'official_code'),
            ],

            'name' => [
                'required',
                'string',
                'max:200',
            ],

            'type' => [
                'required',
                'string',
                Rule::in([
                    'country',
                    'state',
                    'municipality',
                    'locality',
                    'neighborhood',
                    'school_zone',
                    'custom',
                ]),
            ],

            'population' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'area_square_km' => [
                'nullable',
                'numeric',
                'decimal:0,4',
                'gt:0',
                'max:9999999999.9999',
            ],

            'properties' => [
                'sometimes',
                'array',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'boundary' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'boundary.type' => [
                'required_with:boundary',
                'string',
                Rule::in([
                    'MultiPolygon',
                ]),
            ],

            'boundary.coordinates' => [
                'required_with:boundary',
                'array',
                'min:1',
            ],

            'representative_point' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'representative_point.type' => [
                'required_with:representative_point',
                'string',
                Rule::in([
                    'Point',
                ]),
            ],

            'representative_point.coordinates' => [
                'required_with:representative_point',
                'array',
                'size:2',
            ],

            'representative_point.coordinates.0' => [
                'required_with:representative_point.coordinates',
                'numeric',
                'between:-180,180',
            ],

            'representative_point.coordinates.1' => [
                'required_with:representative_point.coordinates',
                'numeric',
                'between:-90,90',
            ],
        ];
    }
}