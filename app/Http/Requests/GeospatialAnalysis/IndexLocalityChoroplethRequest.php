<?php

namespace App\Http\Requests\GeospatialAnalysis;

use Illuminate\Foundation\Http\FormRequest;

class IndexLocalityChoroplethRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('include_geometry')) {
            return;
        }

        $value = $this->query('include_geometry');

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
                'include_geometry' => $normalized,
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

            'include_geometry' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}