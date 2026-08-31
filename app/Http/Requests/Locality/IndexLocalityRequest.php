<?php

namespace App\Http\Requests\Locality;

use Illuminate\Foundation\Http\FormRequest;

class IndexLocalityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_active')) {
            return;
        }

        $value = $this->query('is_active');

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
            'parent_id' => [
                'nullable',
                'uuid',
            ],

            'type' => [
                'nullable',
                'string',
                'in:country,state,municipality,locality,neighborhood,school_zone,custom',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'search' => [
                'nullable',
                'string',
                'max:200',
            ],

            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:500',
            ],
        ];
    }
}