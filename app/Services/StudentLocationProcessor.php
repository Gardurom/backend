<?php

namespace App\Services;

use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class StudentLocationProcessor
{
    /**
     * Registra una posición y actualiza los estados de geocerca.
     *
     * El cliente debe reutilizar positionId al reintentar una petición.
     *
     * @throws JsonException
     */
    public function record(
        Student $student,
        float $longitude,
        float $latitude,
        CarbonInterface $capturedAt,
        ?string $positionId = null,
        ?float $accuracyMeters = null,
        ?float $altitudeMeters = null,
        ?float $speedMetersSecond = null,
        ?float $headingDegrees = null,
        string $source = 'gps',
        ?string $deviceReference = null,
        array $metadata = [],
    ): array {
        $this->validateInput(
            $longitude,
            $latitude,
            $capturedAt,
            $accuracyMeters,
            $speedMetersSecond,
            $headingDegrees,
            $source,
        );

        $positionId ??= (string) Str::uuid7();

        if (! Str::isUuid($positionId)) {
            throw ValidationException::withMessages([
                'position_id' => 'El identificador debe ser un UUID válido.',
            ]);
        }

        return DB::transaction(function () use (
            $student,
            $longitude,
            $latitude,
            $capturedAt,
            $positionId,
            $accuracyMeters,
            $altitudeMeters,
            $speedMetersSecond,
            $headingDegrees,
            $source,
            $deviceReference,
            $metadata,
        ): array {
            /*
             * El bloqueo consultivo evita carreras cuando dos reintentos
             * con el mismo UUID llegan simultáneamente.
             */
            DB::select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                [$positionId]
            );

            $existing = $this->findPosition($positionId);

            if ($existing !== null) {
                if ($existing->student_id !== $student->id) {
                    throw ValidationException::withMessages([
                        'position_id' => 'El UUID ya pertenece a otro alumno.',
                    ]);
                }

                return [
                    'position' => $existing,
                    'events' => [],
                    'was_duplicate' => true,
                ];
            }

            $capturedAtUtc = $capturedAt
                ->copy()
                ->utc()
                ->format('Y-m-d H:i:s.uP');

            $position = DB::selectOne(
                <<<'SQL'
                    INSERT INTO student_positions (
                        id,
                        student_id,
                        captured_at,
                        location,
                        accuracy_meters,
                        altitude_meters,
                        speed_meters_second,
                        heading_degrees,
                        source,
                        device_reference,
                        metadata
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        ST_SetSRID(
                            ST_MakePoint(?, ?),
                            4326
                        )::geography,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?::jsonb
                    )
                    RETURNING
                        id,
                        student_id,
                        captured_at,
                        received_at,
                        accuracy_meters,
                        altitude_meters,
                        speed_meters_second,
                        heading_degrees,
                        source,
                        device_reference,
                        ST_X(location::geometry) AS longitude,
                        ST_Y(location::geometry) AS latitude
                SQL,
                [
                    $positionId,
                    $student->id,
                    $capturedAtUtc,
                    $longitude,
                    $latitude,
                    $accuracyMeters,
                    $altitudeMeters,
                    $speedMetersSecond,
                    $headingDegrees,
                    $source,
                    $deviceReference,
                    json_encode($metadata, JSON_THROW_ON_ERROR),
                ]
            );

            $events = $this->processGeofences(
                student: $student,
                positionId: $positionId,
                capturedAt: $capturedAtUtc,
                longitude: $longitude,
                latitude: $latitude,
                accuracyMeters: $accuracyMeters,
            );

            return [
                'position' => $position,
                'events' => $events,
                'was_duplicate' => false,
            ];
        }, attempts: 3);
    }

    private function findPosition(string $positionId): ?object
    {
        return DB::selectOne(
            <<<'SQL'
                SELECT
                    id,
                    student_id,
                    captured_at,
                    received_at,
                    accuracy_meters,
                    altitude_meters,
                    speed_meters_second,
                    heading_degrees,
                    source,
                    device_reference,
                    ST_X(location::geometry) AS longitude,
                    ST_Y(location::geometry) AS latitude
                FROM student_positions
                WHERE id = ?
                LIMIT 1
            SQL,
            [$positionId]
        );
    }

    private function processGeofences(
        Student $student,
        string $positionId,
        string $capturedAt,
        float $longitude,
        float $latitude,
        ?float $accuracyMeters,
    ): array {
        $geofences = DB::select(
            <<<'SQL'
                SELECT
                    assignment.id AS assignment_id,
                    geofence.id AS geofence_id,
                    geofence.detect_entry,
                    geofence.detect_exit,
                    ST_Covers(
                        geofence.effective_area,
                        ST_SetSRID(
                            ST_MakePoint(?, ?),
                            4326
                        )
                    ) AS is_inside
                FROM geofence_student_assignments AS assignment
                INNER JOIN geofences AS geofence
                    ON geofence.id = assignment.geofence_id
                WHERE assignment.student_id = ?
                  AND assignment.is_active = true
                  AND geofence.is_active = true
                  AND geofence.deleted_at IS NULL
                  AND geofence.effective_area IS NOT NULL
                  AND assignment.starts_at <= ?::timestamptz
                  AND (
                      assignment.ends_at IS NULL
                      OR assignment.ends_at >= ?::timestamptz
                  )
                  AND (
                      geofence.valid_from IS NULL
                      OR geofence.valid_from <= ?::timestamptz
                  )
                  AND (
                      geofence.valid_until IS NULL
                      OR geofence.valid_until >= ?::timestamptz
                  )
            SQL,
            [
                $longitude,
                $latitude,
                $student->id,
                $capturedAt,
                $capturedAt,
                $capturedAt,
                $capturedAt,
            ]
        );

        $events = [];

        foreach ($geofences as $geofence) {
            $isInside = filter_var(
                $geofence->is_inside,
                FILTER_VALIDATE_BOOL
            );

            $state = DB::selectOne(
                <<<'SQL'
                    SELECT is_inside
                    FROM geofence_student_states
                    WHERE geofence_id = ?
                      AND student_id = ?
                    FOR UPDATE
                SQL,
                [
                    $geofence->geofence_id,
                    $student->id,
                ]
            );

            /*
             * La primera posición establece el estado inicial.
             * No genera una entrada artificial.
             */
            if ($state === null) {
                DB::insert(
                    <<<'SQL'
                        INSERT INTO geofence_student_states (
                            geofence_id,
                            student_id,
                            is_inside,
                            last_position_id,
                            last_position_captured_at,
                            state_changed_at,
                            updated_at
                        )
                        VALUES (?, ?, ?, ?, ?::timestamptz, ?::timestamptz, now())
                    SQL,
                    [
                        $geofence->geofence_id,
                        $student->id,
                        $isInside,
                        $positionId,
                        $capturedAt,
                        $capturedAt,
                    ]
                );

                continue;
            }

            $wasInside = filter_var(
                $state->is_inside,
                FILTER_VALIDATE_BOOL
            );

            if ($wasInside === $isInside) {
                DB::update(
                    <<<'SQL'
                        UPDATE geofence_student_states
                        SET
                            last_position_id = ?,
                            last_position_captured_at = ?::timestamptz,
                            updated_at = now()
                        WHERE geofence_id = ?
                          AND student_id = ?
                    SQL,
                    [
                        $positionId,
                        $capturedAt,
                        $geofence->geofence_id,
                        $student->id,
                    ]
                );

                continue;
            }

            $eventType = $isInside ? 'entry' : 'exit';

            $detectEvent = $eventType === 'entry'
                ? filter_var(
                    $geofence->detect_entry,
                    FILTER_VALIDATE_BOOL
                )
                : filter_var(
                    $geofence->detect_exit,
                    FILTER_VALIDATE_BOOL
                );

            if ($detectEvent) {
                $event = DB::selectOne(
                    <<<'SQL'
                        INSERT INTO geofence_events (
                            geofence_id,
                            student_id,
                            assignment_id,
                            position_id,
                            position_captured_at,
                            event_type,
                            occurred_at,
                            location,
                            payload
                        )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?::timestamptz,
                            ?,
                            ?::timestamptz,
                            ST_SetSRID(
                                ST_MakePoint(?, ?),
                                4326
                            )::geography,
                            ?::jsonb
                        )
                        ON CONFLICT (
                            geofence_id,
                            student_id,
                            event_type,
                            position_id
                        )
                        DO NOTHING
                        RETURNING id, event_type, occurred_at
                    SQL,
                    [
                        $geofence->geofence_id,
                        $student->id,
                        $geofence->assignment_id,
                        $positionId,
                        $capturedAt,
                        $eventType,
                        $capturedAt,
                        $longitude,
                        $latitude,
                        json_encode([
                            'accuracy_meters' => $accuracyMeters,
                        ], JSON_THROW_ON_ERROR),
                    ]
                );

                if ($event !== null) {
                    $events[] = $event;
                }
            }

            DB::update(
                <<<'SQL'
                    UPDATE geofence_student_states
                    SET
                        is_inside = ?,
                        last_position_id = ?,
                        last_position_captured_at = ?::timestamptz,
                        state_changed_at = ?::timestamptz,
                        updated_at = now()
                    WHERE geofence_id = ?
                      AND student_id = ?
                SQL,
                [
                    $isInside,
                    $positionId,
                    $capturedAt,
                    $capturedAt,
                    $geofence->geofence_id,
                    $student->id,
                ]
            );
        }

        return $events;
    }

    private function validateInput(
        float $longitude,
        float $latitude,
        CarbonInterface $capturedAt,
        ?float $accuracyMeters,
        ?float $speedMetersSecond,
        ?float $headingDegrees,
        string $source,
    ): void {
        $errors = [];

        if ($longitude < -180 || $longitude > 180) {
            $errors['longitude'] = 'La longitud debe estar entre -180 y 180.';
        }

        if ($latitude < -90 || $latitude > 90) {
            $errors['latitude'] = 'La latitud debe estar entre -90 y 90.';
        }

        if ($capturedAt->isAfter(now()->addMinutes(5))) {
            $errors['captured_at'] = 'La fecha está demasiado adelantada.';
        }

        if ($accuracyMeters !== null && $accuracyMeters < 0) {
            $errors['accuracy_meters'] = 'La precisión no puede ser negativa.';
        }

        if ($speedMetersSecond !== null && $speedMetersSecond < 0) {
            $errors['speed'] = 'La velocidad no puede ser negativa.';
        }

        if (
            $headingDegrees !== null
            && ($headingDegrees < 0 || $headingDegrees >= 360)
        ) {
            $errors['heading'] = 'El rumbo debe estar entre 0 y 359.99.';
        }

        if (! in_array(
            $source,
            ['gps', 'mobile', 'manual', 'import', 'device'],
            true
        )) {
            $errors['source'] = 'La fuente de ubicación no es válida.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}