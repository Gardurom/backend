<?php

namespace App\Http\Requests\Subject;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'campus_id' => trim(
                (string) $this->input('campus_id')
            ),
            'code' => mb_strtoupper(
                trim((string) $this->input('code'))
            ),
            'name' => $this->normalizeRequiredText(
                $this->input('name')
            ),
            'description' => $this->normalizeNullableText(
                $this->input('description')
            ),
        ]);
    }

    public function rules(): array
    {
        $headerCampusId = trim(
            (string) $this->header('X-Campus-ID')
        );

        return [
            'campus_id' => [
                'required',
                'uuid',
                Rule::in([$headerCampusId]),
                Rule::exists('campuses', 'id')
                    ->where(
                        fn ($query) => $query->where(
                            'is_active',
                            true
                        )
                    ),
            ],

            'code' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('subjects', 'code')
                    ->where(
                        fn ($query) => $query->where(
                            'campus_id',
                            $headerCampusId
                        )
                    ),
            ],

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

            'weekly_hours' => [
                'nullable',
                'numeric',
                'decimal:0,2',
                'gt:0',
                'max:999.99',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'campus_id.required' =>
                'El plantel es obligatorio.',

            'campus_id.uuid' =>
                'El identificador del plantel no es válido.',

            'campus_id.in' =>
                'El plantel del cuerpo debe coincidir con X-Campus-ID.',

            'campus_id.exists' =>
                'El plantel no existe o está inactivo.',

            'code.required' =>
                'El código de la materia es obligatorio.',

            'code.regex' =>
                'El código solo puede contener letras, números, guiones y guiones bajos.',

            'code.unique' =>
                'El código de la materia ya existe en este plantel.',

            'name.required' =>
                'El nombre de la materia es obligatorio.',

            'weekly_hours.numeric' =>
                'Las horas semanales deben ser numéricas.',

            'weekly_hours.decimal' =>
                'Las horas semanales pueden tener hasta dos decimales.',

            'weekly_hours.gt' =>
                'Las horas semanales deben ser mayores que cero.',

            'weekly_hours.max' =>
                'Las horas semanales no pueden ser mayores que 999.99.',
        ];
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

        $value = preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        );

        return $value === ''
            ? null
            : $value;
    }
}