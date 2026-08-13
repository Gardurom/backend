<?php

namespace App\Http\Requests\Subject;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('code')) {
            $values['code'] = mb_strtoupper(
                trim((string) $this->input('code'))
            );
        }

        if ($this->exists('name')) {
            $values['name'] = $this->normalizeRequiredText(
                $this->input('name')
            );
        }

        if ($this->exists('description')) {
            $values['description'] =
                $this->normalizeNullableText(
                    $this->input('description')
                );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        /** @var Subject|null $subject */
        $subject = $this->route('subject');

        if (! $subject instanceof Subject) {
            return [];
        }

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('subjects', 'code')
                    ->where(
                        fn ($query) => $query->where(
                            'campus_id',
                            $subject->campus_id
                        )
                    )
                    ->ignore($subject->id),
            ],

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

            'weekly_hours' => [
                'sometimes',
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
            'code.required' =>
                'El código de la materia no puede quedar vacío.',

            'code.regex' =>
                'El código solo puede contener letras, números, guiones y guiones bajos.',

            'code.unique' =>
                'El código de la materia ya existe en este plantel.',

            'name.required' =>
                'El nombre de la materia no puede quedar vacío.',

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