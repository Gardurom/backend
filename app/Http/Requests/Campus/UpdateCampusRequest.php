<?php

namespace App\Http\Requests\Campus;

use App\Models\Campus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->has('code')) {
            $values['code'] = strtoupper(
                trim((string) $this->input('code'))
            );
        }

        if ($this->has('country_code')) {
            $values['country_code'] = strtoupper(
                trim((string) $this->input('country_code'))
            );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        /** @var Campus $campus */
        $campus = $this->route('campus');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('campuses', 'code')->ignore($campus),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'official_key' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('campuses', 'official_key')
                    ->ignore($campus),
            ],
            'email' => [
                'sometimes',
                'nullable',
                'email:rfc',
                'max:254',
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'street' => ['sometimes', 'nullable', 'string', 'max:150'],
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
                'regex:/^[0-9]{5}$/',
            ],
            'locality' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],
            'municipality' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],
            'state' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],
            'country_code' => [
                'sometimes',
                'required',
                'string',
                'size:2',
                'regex:/^[A-Z]{2}$/',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}