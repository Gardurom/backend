<?php

namespace App\Http\Requests\Campus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'country_code' => strtoupper(
                trim((string) $this->input('country_code', 'MX'))
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('campuses', 'code'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'official_key' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('campuses', 'official_key'),
            ],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'],
            'street' => ['nullable', 'string', 'max:150'],
            'external_number' => ['nullable', 'string', 'max:20'],
            'internal_number' => ['nullable', 'string', 'max:20'],
            'neighborhood' => ['nullable', 'string', 'max:150'],
            'postal_code' => [
                'nullable',
                'string',
                'regex:/^[0-9]{5}$/',
            ],
            'locality' => ['nullable', 'string', 'max:150'],
            'municipality' => ['nullable', 'string', 'max:150'],
            'state' => ['nullable', 'string', 'max:100'],
            'country_code' => [
                'required',
                'string',
                'size:2',
                'regex:/^[A-Z]{2}$/',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}