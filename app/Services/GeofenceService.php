<?php

namespace App\Services;

use App\Models\Geofence;
use App\Models\GeofenceStudentAssignment;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class GeofenceService
{
    /**
     * @throws JsonException
     */
    public function setPolygon(
        Geofence $geofence,
        array|string $geoJson,
    ): Geofence {
        $json = is_array($geoJson)
            ? json_encode($geoJson, JSON_THROW_ON_ERROR)
            : $geoJson;

        $this->validatePolygonGeoJson($json);

        DB::update(
            <<<'SQL'
                WITH input AS (
                    SELECT ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    ) AS geom
                ),
                normalized AS (
                    SELECT ST_Multi(
                        ST_CollectionExtract(
                            ST_MakeValid(geom),
                            3
                        )
                    ) AS geom
                    FROM input
                )
                UPDATE geofences
                SET
                    type = 'polygon',
                    effective_area = normalized.geom,
                    center = NULL,
                    radius_meters = NULL,
                    updated_at = now()
                FROM normalized
                WHERE geofences.id = ?
                  AND geofences.deleted_at IS NULL
            SQL,
            [$json, $geofence->id]
        );

        return $geofence->refresh();
    }

    public function setCircle(
        Geofence $geofence,
        float $longitude,
        float $latitude,
        float $radiusMeters,
    ): Geofence {
        $this->validateCoordinates($longitude, $latitude);
        $this->validateRadius($radiusMeters);

        DB::update(
            <<<'SQL'
                WITH circle AS (
                    SELECT
                        ST_SetSRID(
                            ST_MakePoint(?, ?),
                            4326
                        )::geography AS center,
                        ST_Multi(
                            ST_Buffer(
                                ST_SetSRID(
                                    ST_MakePoint(?, ?),
                                    4326
                                )::geography,
                                ?
                            )::geometry
                        ) AS area
                )
                UPDATE geofences
                SET
                    type = 'circle',
                    center = circle.center,
                    radius_meters = ?,
                    effective_area = circle.area,
                    updated_at = now()
                FROM circle
                WHERE geofences.id = ?
                  AND geofences.deleted_at IS NULL
            SQL,
            [
                $longitude,
                $latitude,
                $longitude,
                $latitude,
                $radiusMeters,
                $radiusMeters,
                $geofence->id,
            ]
        );

        return $geofence->refresh();
    }

    /**
     * @throws JsonException
     */
    public function setCorridor(
        Geofence $geofence,
        array|string $lineGeoJson,
        float $bufferMeters,
    ): Geofence {
        $this->validateRadius($bufferMeters);

        $json = is_array($lineGeoJson)
            ? json_encode($lineGeoJson, JSON_THROW_ON_ERROR)
            : $lineGeoJson;

        $analysis = DB::selectOne(
            <<<'SQL'
                WITH input AS (
                    SELECT ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    ) AS geom
                )
                SELECT
                    GeometryType(geom) AS geometry_type,
                    ST_IsValid(geom) AS is_valid,
                    ST_IsEmpty(geom) AS is_empty,
                    ST_NPoints(geom) AS point_count
                FROM input
            SQL,
            [$json]
        );

        if (
            ! $analysis
            || $analysis->is_empty
            || ! in_array(
                $analysis->geometry_type,
                ['LINESTRING', 'MULTILINESTRING'],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'geometry' => 'El corredor requiere una línea GeoJSON válida.',
            ]);
        }

        if ((int) $analysis->point_count > 100000) {
            throw ValidationException::withMessages([
                'geometry' => 'La línea tiene demasiados vértices.',
            ]);
        }

        DB::update(
            <<<'SQL'
                WITH input AS (
                    SELECT ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    ) AS geom
                ),
                corridor AS (
                    SELECT ST_Multi(
                        ST_Buffer(
                            geom::geography,
                            ?
                        )::geometry
                    ) AS area
                    FROM input
                )
                UPDATE geofences
                SET
                    type = 'corridor',
                    center = NULL,
                    radius_meters = ?,
                    effective_area = corridor.area,
                    updated_at = now()
                FROM corridor
                WHERE geofences.id = ?
                  AND geofences.deleted_at IS NULL
            SQL,
            [
                $json,
                $bufferMeters,
                $bufferMeters,
                $geofence->id,
            ]
        );

        return $geofence->refresh();
    }

    public function containsPoint(
        Geofence $geofence,
        float $longitude,
        float $latitude,
    ): bool {
        $this->validateCoordinates($longitude, $latitude);

        $result = DB::selectOne(
            <<<'SQL'
                SELECT ST_Covers(
                    effective_area,
                    ST_SetSRID(
                        ST_MakePoint(?, ?),
                        4326
                    )
                ) AS contains
                FROM geofences
                WHERE id = ?
                  AND deleted_at IS NULL
                  AND is_active = true
                  AND effective_area IS NOT NULL
            SQL,
            [
                $longitude,
                $latitude,
                $geofence->id,
            ]
        );

        return (bool) ($result?->contains ?? false);
    }

    public function assignStudent(
        Geofence $geofence,
        Student $student,
        string $consentReference,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt = null,
        ?User $authorizedBy = null,
        array $notificationSettings = [],
    ): GeofenceStudentAssignment {
        if ($student->campus_id !== $geofence->campus_id) {
            throw ValidationException::withMessages([
                'student_id' => 'El alumno pertenece a otro plantel.',
            ]);
        }

        if (trim($consentReference) === '') {
            throw ValidationException::withMessages([
                'consent_reference' => 'La autorización es obligatoria.',
            ]);
        }

        if ($endsAt !== null && $endsAt->lt($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'La fecha final no puede ser anterior al inicio.',
            ]);
        }

        return DB::transaction(function () use (
            $geofence,
            $student,
            $consentReference,
            $startsAt,
            $endsAt,
            $authorizedBy,
            $notificationSettings,
        ): GeofenceStudentAssignment {
            $existing = GeofenceStudentAssignment::query()
                ->where('geofence_id', $geofence->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            $values = [
                'authorized_by_user_id' => $authorizedBy?->id,
                'consent_reference' => trim($consentReference),
                'authorized_at' => now(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'notification_settings' => $notificationSettings,
                'is_active' => true,
            ];

            if ($existing) {
                $existing->update($values);

                return $existing->refresh();
            }

            return GeofenceStudentAssignment::create([
                'geofence_id' => $geofence->id,
                'student_id' => $student->id,
                ...$values,
            ]);
        });
    }

    public function geometryAsGeoJson(Geofence $geofence): ?array
    {
        $result = DB::selectOne(
            <<<'SQL'
                SELECT ST_AsGeoJSON(effective_area) AS geojson
                FROM geofences
                WHERE id = ?
                  AND deleted_at IS NULL
            SQL,
            [$geofence->id]
        );

        if (! $result?->geojson) {
            return null;
        }

        return json_decode(
            $result->geojson,
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function validatePolygonGeoJson(string $json): void
    {
        $analysis = DB::selectOne(
            <<<'SQL'
                WITH input AS (
                    SELECT ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    ) AS geom
                ),
                normalized AS (
                    SELECT ST_Multi(
                        ST_CollectionExtract(
                            ST_MakeValid(geom),
                            3
                        )
                    ) AS geom
                    FROM input
                )
                SELECT
                    ST_IsValid(geom) AS is_valid,
                    ST_IsEmpty(geom) AS is_empty,
                    ST_NPoints(geom) AS point_count
                FROM normalized
            SQL,
            [$json]
        );

        if (
            ! $analysis
            || ! $analysis->is_valid
            || $analysis->is_empty
        ) {
            throw ValidationException::withMessages([
                'geometry' => 'El polígono GeoJSON no es válido.',
            ]);
        }

        if ((int) $analysis->point_count > 100000) {
            throw ValidationException::withMessages([
                'geometry' => 'El polígono tiene demasiados vértices.',
            ]);
        }
    }

    private function validateCoordinates(
        float $longitude,
        float $latitude,
    ): void {
        $errors = [];

        if ($longitude < -180 || $longitude > 180) {
            $errors['longitude'] = 'La longitud debe estar entre -180 y 180.';
        }

        if ($latitude < -90 || $latitude > 90) {
            $errors['latitude'] = 'La latitud debe estar entre -90 y 90.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateRadius(float $radiusMeters): void
    {
        if ($radiusMeters < 1 || $radiusMeters > 100000) {
            throw ValidationException::withMessages([
                'radius_meters' => 'El radio debe estar entre 1 y 100000 metros.',
            ]);
        }
    }
}