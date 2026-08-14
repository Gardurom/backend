<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records' => [
                'required',
                'array',
                'min:1',
                'max:500',
            ],

            'records.*.enrollment_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('enrollments', 'id')
                    ->whereNull('deleted_at'),
            ],

            'records.*.status' => [
                'required',
                'string',
                Rule::in([
                    'present',
                    'absent',
                    'late',
                    'excused',
                ]),
            ],

            'records.*.minutes_late' => [
                'required',
                'integer',
                'min:0',
                'max:1440',
            ],

            'records.*.notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'records.required' => 'Debe proporcionar los registros de asistencia.',
            'records.array' => 'Los registros de asistencia deben ser una lista.',
            'records.min' => 'Debe proporcionar al menos un registro de asistencia.',
            'records.max' => 'No puede procesar más de 500 registros por solicitud.',

            'records.*.enrollment_id.required' =>
                'La inscripción es obligatoria.',

            'records.*.enrollment_id.uuid' =>
                'El identificador de la inscripción no es válido.',

            'records.*.enrollment_id.distinct' =>
                'Una inscripción no puede aparecer más de una vez.',

            'records.*.enrollment_id.exists' =>
                'La inscripción seleccionada no existe.',

            'records.*.status.required' =>
                'El estado de asistencia es obligatorio.',

            'records.*.status.in' =>
                'El estado debe ser present, absent, late o excused.',

            'records.*.minutes_late.required' =>
                'Los minutos de retraso son obligatorios.',

            'records.*.minutes_late.integer' =>
                'Los minutos de retraso deben ser un número entero.',

            'records.*.minutes_late.min' =>
                'Los minutos de retraso no pueden ser negativos.',

            'records.*.minutes_late.max' =>
                'Los minutos de retraso no pueden superar 1440.',

            'records.*.notes.string' =>
                'Las observaciones deben ser texto.',

            'records.*.notes.max' =>
                'Las observaciones no pueden superar 2000 caracteres.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $attendanceSession = $this->attendanceSession();

            if (! $attendanceSession) {
                $validator->errors()->add(
                    'attendance_session',
                    'No fue posible identificar la sesión de asistencia.'
                );

                return;
            }

            if ($attendanceSession->status !== 'open') {
                $validator->errors()->add(
                    'attendance_session',
                    'Solo se pueden registrar asistencias en una sesión abierta.'
                );

                return;
            }

            $records = $this->input('records', []);

            if (! is_array($records)) {
                return;
            }

            $enrollmentIds = collect($records)
                ->pluck('enrollment_id')
                ->filter()
                ->unique()
                ->values();

            if ($enrollmentIds->isEmpty()) {
                return;
            }

            $validEnrollmentIds = $attendanceSession
                ->schoolGroup
                ->enrollments()
                ->whereIn('id', $enrollmentIds->all())
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(
                    fn (mixed $id): string => (string) $id
                )
                ->all();

            $validEnrollmentLookup = array_fill_keys(
                $validEnrollmentIds,
                true
            );

            foreach ($records as $index => $record) {
                if (! is_array($record)) {
                    continue;
                }

                $enrollmentId = $record['enrollment_id'] ?? null;
                $status = $record['status'] ?? null;
                $minutesLate = $record['minutes_late'] ?? null;

                if (
                    is_string($enrollmentId)
                    && ! isset($validEnrollmentLookup[$enrollmentId])
                ) {
                    $validator->errors()->add(
                        "records.{$index}.enrollment_id",
                        'La inscripción no pertenece al grupo de esta sesión o no está activa.'
                    );
                }

                if (
                    $status === 'late'
                    && is_numeric($minutesLate)
                    && (int) $minutesLate <= 0
                ) {
                    $validator->errors()->add(
                        "records.{$index}.minutes_late",
                        'Una asistencia con retraso debe tener al menos un minuto de retraso.'
                    );
                }

                if (
                    is_string($status)
                    && $status !== 'late'
                    && is_numeric($minutesLate)
                    && (int) $minutesLate !== 0
                ) {
                    $validator->errors()->add(
                        "records.{$index}.minutes_late",
                        'Los minutos deben ser cero cuando el estado no es late.'
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $records = $this->input('records');

        if (! is_array($records)) {
            return;
        }

        $normalizedRecords = collect($records)
            ->map(function (mixed $record): mixed {
                if (! is_array($record)) {
                    return $record;
                }

                if (isset($record['enrollment_id'])) {
                    $record['enrollment_id'] = trim(
                        (string) $record['enrollment_id']
                    );
                }

                if (isset($record['status'])) {
                    $record['status'] = strtolower(
                        trim((string) $record['status'])
                    );
                }

                $record['minutes_late'] = isset(
                    $record['minutes_late']
                )
                    ? (int) $record['minutes_late']
                    : 0;

                if (array_key_exists('notes', $record)) {
                    $notes = is_string($record['notes'])
                        ? trim($record['notes'])
                        : $record['notes'];

                    $record['notes'] = $notes === ''
                        ? null
                        : $notes;
                }

                return $record;
            })
            ->all();

        $this->merge([
            'records' => $normalizedRecords,
        ]);
    }

    private function attendanceSession(): ?AttendanceSession
    {
        $attendanceSession = $this->route(
            'attendanceSession'
        );

        if ($attendanceSession instanceof AttendanceSession) {
            return $attendanceSession;
        }

        if (! is_string($attendanceSession)) {
            return null;
        }

        return AttendanceSession::query()
            ->with('schoolGroup')
            ->find($attendanceSession);
    }
}