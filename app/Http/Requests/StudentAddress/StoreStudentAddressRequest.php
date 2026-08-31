<?php

namespace App\Http\Requests\StudentAddress;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locality_id' => [
                'nullable',
                'uuid',
                Rule::exists('localities', 'id'),
            ],

            'street' => [
                'nullable',
                'string',
                'max:150',
            ],

            'external_number' => [
                'nullable',
                'string',
                'max:20',
            ],

            'internal_number' => [
                'nullable',
                'string',
                'max:20',
            ],

            'neighborhood' => [
                'nullable',
                'string',
                'max:150',
            ],

            'postal_code' => [
                'nullable',
                'string',
                'max:10',
            ],

            'address_reference' => [
                'nullable',
                'string',
            ],

            'accuracy_meters' => [
                'nullable',
                'numeric',
                'min:0',
                'max:99999999.99',
            ],

            'location_source' => [
                'sometimes',
                'string',
                Rule::in([
                    'manual',
                    'gps',
                    'geocoder',
                    'import',
                    'official_catalog',
                ]),
            ],

            'is_primary' => [
                'sometimes',
                'boolean',
            ],

            'is_verified' => [
                'sometimes',
                'boolean',
            ],

            'valid_from' => [
                'required',
                'date',
            ],

            'valid_until' => [
                'nullable',
                'date',
                'after_or_equal:valid_from',
            ],

            'verified_at' => [
                'nullable',
                'date',
                Rule::requiredIf(
                    fn (): bool =>
                        $this->boolean('is_verified')
                ),
            ],

            'location' => [
                'sometimes',
                'nullable',
                'array',
            ],

            'location.type' => [
                'required_with:location',
                'string',
                Rule::in([
                    'Point',
                ]),
            ],

            'location.coordinates' => [
                'required_with:location',
                'array',
                'size:2',
            ],

            'location.coordinates.0' => [
                'required_with:location.coordinates',
                'numeric',
                'between:-180,180',
            ],

            'location.coordinates.1' => [
                'required_with:location.coordinates',
                'numeric',
                'between:-90,90',
            ],
        ];
    }
}