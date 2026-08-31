<?php

namespace App\Http\Requests\Locality;

use App\Models\Locality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLocalityRequest extends FormRequest
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
        $locality = $this->route('locality');

        $localityId = $locality instanceof Locality
            ? $locality->getKey()
            : $locality;

        return [
            'parent_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('localities', 'id'),
                Rule::notIn([$localityId]),
            ],

            'official_code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('localities', 'official_code')
                    ->ignore($localityId),
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:200',
            ],

            'type' => [
                'sometimes',
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
                'sometimes',
                'nullable',
                'integer',
                'min:0',
            ],

            'area_square_km' => [
                'sometimes',
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

    public function withValidator(
        Validator $validator,
    ): void {
        $validator->after(function (Validator $validator): void {
            if (
                ! $this->has('parent_id')
                || $this->input('parent_id') === null
                || $validator->errors()->has('parent_id')
            ) {
                return;
            }

            $locality = $this->route('locality');

            $localityId = $locality instanceof Locality
                ? (string) $locality->getKey()
                : (string) $locality;

            $parentId = (string) $this->input('parent_id');

            $visited = [];

            while ($parentId !== '') {
                if ($parentId === $localityId) {
                    $validator->errors()->add(
                        'parent_id',
                        'La localidad seleccionada produciria un ciclo en la jerarquia.'
                    );

                    return;
                }

                if (isset($visited[$parentId])) {
                    $validator->errors()->add(
                        'parent_id',
                        'La jerarquia seleccionada contiene un ciclo.'
                    );

                    return;
                }

                $visited[$parentId] = true;

                $parent = Locality::query()
                    ->select([
                        'id',
                        'parent_id',
                    ])
                    ->find($parentId);

                if ($parent === null) {
                    return;
                }

                if ($parent->parent_id === null) {
                    return;
                }

                $parentId = (string) $parent->parent_id;
            }
        });
    }
}