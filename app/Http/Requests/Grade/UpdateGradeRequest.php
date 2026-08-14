<?php

namespace App\Http\Requests\Grade;

use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('feedback')) {
            $values['feedback'] =
                $this->normalizeNullableText(
                    $this->input('feedback')
                );
        }

        if ($this->exists('status')) {
            $values['status'] = mb_strtolower(
                trim((string) $this->input('status'))
            );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'score' => [
                'sometimes',
                'nullable',
                'numeric',
                'decimal:0,2',
                'gte:0',
            ],

            'feedback' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
            ],

            'graded_at' => [
                'sometimes',
                'nullable',
                'date',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'pending',
                    'graded',
                    'exempt',
                    'cancelled',
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Grade|null $grade */
                $grade = $this->route('grade');

                if (! $grade instanceof Grade) {
                    $validator->errors()->add(
                        'grade',
                        'La calificación no es válida.'
                    );

                    return;
                }

                $grade->loadMissing('assessment');

                $status = (string) $this->input(
                    'status',
                    $grade->status
                );

                $score = $this->exists('score')
                    ? $this->input('score')
                    : $grade->score;

                if (
                    $status === 'graded'
                    && $score === null
                ) {
                    $validator->errors()->add(
                        'score',
                        'La puntuación es obligatoria cuando el estado es graded.'
                    );

                    return;
                }

                if (
                    $status !== 'graded'
                    && $score !== null
                ) {
                    $validator->errors()->add(
                        'score',
                        'La puntuación debe ser nula cuando el estado no es graded.'
                    );

                    return;
                }

                if (
                    $score !== null
                    && (float) $score
                        > (float) $grade
                            ->assessment
                            ->maximum_score
                ) {
                    $validator->errors()->add(
                        'score',
                        'La puntuación no puede superar la puntuación máxima de la evaluación.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'score.numeric' =>
                'La puntuación debe ser numérica.',

            'score.decimal' =>
                'La puntuación puede tener hasta dos decimales.',

            'score.gte' =>
                'La puntuación no puede ser negativa.',

            'status.in' =>
                'El estado de la calificación no es válido.',
        ];
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

        $normalized = preg_replace(
            '/\s+/u',
            ' ',
            trim($value)
        );

        return $normalized === ''
            ? null
            : $normalized;
    }
}