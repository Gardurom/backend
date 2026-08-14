<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->exists('status')) {
            $values['status'] = mb_strtolower(
                trim((string) $this->input('status'))
            );
        }

        if ($this->exists('notes')) {
            $values['notes'] =
                $this->normalizeNullableText(
                    $this->input('notes')
                );
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'starts_at' => [
                'sometimes',
                'nullable',
                'date_format:H:i',
            ],

            'ends_at' => [
                'sometimes',
                'nullable',
                'date_format:H:i',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'scheduled',
                    'open',
                    'closed',
                    'cancelled',
                ]),
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:5000',
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

                /** @var AttendanceSession|null $session */
                $session = $this->route(
                    'attendanceSession'
                );

                if (
                    ! $session
                    instanceof AttendanceSession
                ) {
                    $validator->errors()->add(
                        'attendance_session',
                        'La sesión de asistencia no es válida.'
                    );

                    return;
                }

                $startsAt = $this->input(
                    'starts_at',
                    $session->starts_at
                );

                $endsAt = $this->input(
                    'ends_at',
                    $session->ends_at
                );

                if (
                    $startsAt
                    && $endsAt
                    && $endsAt <= $startsAt
                ) {
                    $validator->errors()->add(
                        'ends_at',
                        'La hora final debe ser posterior a la hora inicial.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'starts_at.date_format' =>
                'La hora inicial debe tener el formato HH:MM.',

            'ends_at.date_format' =>
                'La hora final debe tener el formato HH:MM.',

            'status.in' =>
                'El estado de la sesión no es válido.',
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