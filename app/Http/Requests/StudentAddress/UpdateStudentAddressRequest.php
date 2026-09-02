<?php

namespace App\Http\Requests\StudentAddress;

use App\Models\StudentAddress;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class UpdateStudentAddressRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('verified_at')) {
            return;
        }

        $verifiedAt = $this->input('verified_at');

        if (
            ! is_string($verifiedAt)
            || trim($verifiedAt) === ''
        ) {
            return;
        }

        try {
            $normalizedVerifiedAt = CarbonImmutable::parse(
                $verifiedAt
            )
                ->utc()
                ->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return;
        }

        $this->merge([
            'verified_at' => $normalizedVerifiedAt,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locality_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('localities', 'id'),
            ],

            'street' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'external_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
            ],

            'internal_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
            ],

            'neighborhood' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'postal_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:10',
            ],

            'address_reference' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'accuracy_meters' => [
                'sometimes',
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
                'sometimes',
                'required',
                'date',
            ],

            'valid_until' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'verified_at' => [
                'sometimes',
                'nullable',
                'date',
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

    public function withValidator(
        Validator $validator,
    ): void {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $address = $this->route('address');

            if (! $address instanceof StudentAddress) {
                return;
            }

            $validFrom = $this->has('valid_from')
                ? $this->input('valid_from')
                : $address->valid_from?->toDateString();

            $validUntil = $this->has('valid_until')
                ? $this->input('valid_until')
                : $address->valid_until?->toDateString();

            if (
                $validUntil !== null
                && $validFrom !== null
                && strtotime((string) $validUntil)
                    < strtotime((string) $validFrom)
            ) {
                $validator->errors()->add(
                    'valid_until',
                    'La fecha final debe ser igual o posterior a la fecha inicial.'
                );
            }

            $isVerified = $this->has('is_verified')
                ? $this->boolean('is_verified')
                : (bool) $address->is_verified;

            $verifiedAt = $this->has('verified_at')
                ? $this->input('verified_at')
                : $address->verified_at;

            if (
                $isVerified
                && $verifiedAt === null
            ) {
                $validator->errors()->add(
                    'verified_at',
                    'La fecha de verificacion es obligatoria cuando el domicilio esta verificado.'
                );
            }
        });
    }
}