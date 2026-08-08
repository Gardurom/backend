<?php

namespace App\Services;

use App\Models\Locality;
use App\Models\StudentAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class GeospatialService
{
    public function setStudentAddressLocation(
        StudentAddress $address,
        float $longitude,
        float $latitude,
        ?float $accuracyMeters = null,
    ): StudentAddress {
        $this->validateCoordinates($longitude, $latitude);

        if ($accuracyMeters !== null && $accuracyMeters < 0) {
            throw ValidationException::withMessages([
                'accuracy_meters' => 'La precisión no puede ser negativa.',
            ]);
        }

        DB::update(
            <<<'SQL'
                UPDATE student_addresses
                SET
                    location = ST_SetSRID(
                        ST_MakePoint(?, ?),
                        4326
                    )::geography,
                    accuracy_meters = COALESCE(?, accuracy_meters),
                    updated_at = now()
                WHERE id = ?
                  AND deleted_at IS NULL
            SQL,
            [
                $longitude,
                $latitude,
                $accuracyMeters,
                $address->id,
            ]
        );

        return $address->refresh();
    }

    /**
     * @throws JsonException
     */
    public function setLocalityBoundary(
        Locality $locality,
        array|string $geoJson,
    ): Locality {
        $json = is_array($geoJson)
            ? json_encode($geoJson, JSON_THROW_ON_ERROR)
            : $geoJson;

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
                'boundary' => 'El polígono proporcionado no es válido.',
            ]);
        }

        if ((int) $analysis->point_count > 100000) {
            throw ValidationException::withMessages([
                'boundary' => 'El polígono tiene demasiados vértices.',
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
                normalized AS (
                    SELECT ST_Multi(
                        ST_CollectionExtract(
                            ST_MakeValid(geom),
                            3
                        )
                    ) AS geom
                    FROM input
                )
                UPDATE localities
                SET
                    boundary = normalized.geom,
                    representative_point =
                        ST_PointOnSurface(normalized.geom)::geography,
                    area_square_km =
                        ST_Area(normalized.geom::geography) / 1000000,
                    updated_at = now()
                FROM normalized
                WHERE localities.id = ?
            SQL,
            [$json, $locality->id]
        );

        return $locality->refresh();
    }

    public function localityAsGeoJson(Locality $locality): ?array
    {
        $result = DB::selectOne(
            <<<'SQL'
                SELECT ST_AsGeoJSON(boundary) AS geojson
                FROM localities
                WHERE id = ?
            SQL,
            [$locality->id]
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

    public function addressesWithinRadius(
        float $longitude,
        float $latitude,
        float $radiusMeters,
    ): Collection {
        $this->validateCoordinates($longitude, $latitude);

        if ($radiusMeters <= 0 || $radiusMeters > 100000) {
            throw ValidationException::withMessages([
                'radius_meters' => 'El radio debe estar entre 1 y 100000 metros.',
            ]);
        }

        return DB::table('student_addresses')
            ->select([
                'id',
                'student_id',
                'locality_id',
                'accuracy_meters',
            ])
            ->selectRaw(
                <<<'SQL'
                    ST_Distance(
                        location,
                        ST_SetSRID(
                            ST_MakePoint(?, ?),
                            4326
                        )::geography
                    ) AS distance_meters
                SQL,
                [$longitude, $latitude]
            )
            ->whereNull('deleted_at')
            ->whereNotNull('location')
            ->whereRaw(
                <<<'SQL'
                    ST_DWithin(
                        location,
                        ST_SetSRID(
                            ST_MakePoint(?, ?),
                            4326
                        )::geography,
                        ?
                    )
                SQL,
                [$longitude, $latitude, $radiusMeters]
            )
            ->orderBy('distance_meters')
            ->limit(1000)
            ->get();
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
}