<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Locality\IndexLocalityRequest;
use App\Http\Requests\Locality\StoreLocalityRequest;
use App\Http\Requests\Locality\UpdateLocalityRequest;
use App\Http\Resources\LocalityDetailResource;
use App\Http\Resources\LocalityResource;
use App\Models\Locality;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class LocalityController extends Controller
{
    public function index(
        IndexLocalityRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $limit = (int) ($validated['limit'] ?? 100);

        $query = Locality::query();

        if (array_key_exists('parent_id', $validated)) {
            $query->where(
                'parent_id',
                $validated['parent_id'],
            );
        }

        if (isset($validated['type'])) {
            $query->where(
                'type',
                $validated['type'],
            );
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where(
                'is_active',
                $request->boolean('is_active'),
            );
        }

        if (isset($validated['search'])) {
            $search = trim($validated['search']);

            if ($search !== '') {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where(
                            'name',
                            'ILIKE',
                            '%' . $search . '%',
                        )
                        ->orWhere(
                            'official_code',
                            'ILIKE',
                            '%' . $search . '%',
                        );
                });
            }
        }

        $localities = $query
            ->orderBy('type')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => LocalityResource::collection(
                $localities
            ),
            'meta' => [
                'limit' => $limit,
                'count' => $localities->count(),
            ],
        ]);
    }

    public function store(
        StoreLocalityRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $locality = DB::transaction(
            function () use ($validated): Locality {
                $attributes = [
                    'parent_id' => $validated['parent_id'] ?? null,
                    'official_code' => $validated['official_code'],
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                    'population' => $validated['population'] ?? null,
                    'area_square_km' => $validated['area_square_km'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                ];

                if (array_key_exists('properties', $validated)) {
                    $attributes['properties'] = $validated['properties'];
                }

                $locality = Locality::query()->create(
                    $attributes
                );

                $spatialChanged = $this->applySpatialFields(
                    $locality,
                    $validated,
                );

                if ($spatialChanged) {
                    $locality->touch();
                }

                return $locality;
            }
        );

        $locality = $this->findLocalityWithSpatialData(
            $locality->getKey()
        );

        return response()->json([
            'data' => new LocalityDetailResource($locality),
        ], 201);
    }

    public function show(
        Locality $locality,
    ): JsonResponse {
        $locality = $this->findLocalityWithSpatialData(
            $locality->getKey()
        );

        return response()->json([
            'data' => new LocalityDetailResource($locality),
        ]);
    }

    public function update(
        UpdateLocalityRequest $request,
        Locality $locality,
    ): JsonResponse {
        $validated = $request->validated();

        DB::transaction(
            function () use (
                $validated,
                $locality,
            ): void {
                $attributes = $validated;

                unset(
                    $attributes['boundary'],
                    $attributes['representative_point'],
                );

                $locality->fill($attributes);
                $locality->save();

                $spatialChanged = $this->applySpatialFields(
                    $locality,
                    $validated,
                );

                if ($spatialChanged) {
                    $locality->touch();
                }
            }
        );

        $locality = $this->findLocalityWithSpatialData(
            $locality->getKey()
        );

        return response()->json([
            'data' => new LocalityDetailResource($locality),
        ]);
    }

    public function destroy(
        Locality $locality,
    ): JsonResponse {
        if ($locality->children()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la localidad porque tiene localidades hijas asociadas.',
            ], 409);
        }

        if ($locality->studentAddresses()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la localidad porque tiene domicilios de alumnos asociados.',
            ], 409);
        }

        try {
            $locality->delete();
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                return response()->json([
                    'message' => 'No se puede eliminar la localidad porque tiene registros relacionados.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(
            null,
            204
        );
    }

    private function findLocalityWithSpatialData(
        string $localityId,
    ): Locality {
        return Locality::query()
            ->select('localities.*')
            ->selectRaw(
                'ST_AsGeoJSON(boundary) AS boundary_geojson'
            )
            ->selectRaw(
                'ST_AsGeoJSON(representative_point::geometry) AS representative_point_geojson'
            )
            ->findOrFail($localityId);
    }

    private function applySpatialFields(
        Locality $locality,
        array $validated,
    ): bool {
        $changed = false;

        if (array_key_exists('boundary', $validated)) {
            $changed = true;

            if ($validated['boundary'] === null) {
                DB::table('localities')
                    ->where('id', $locality->getKey())
                    ->update([
                        'boundary' => null,
                    ]);
            } else {
                $boundaryGeoJson = $this->validateBoundary(
                    $validated['boundary']
                );

                DB::update(
                    <<<'SQL'
                    UPDATE localities
                    SET boundary = ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    )
                    WHERE id = ?
                    SQL,
                    [
                        $boundaryGeoJson,
                        $locality->getKey(),
                    ],
                );
            }
        }

        if (
            array_key_exists(
                'representative_point',
                $validated
            )
        ) {
            $changed = true;

            if (
                $validated['representative_point']
                === null
            ) {
                DB::table('localities')
                    ->where('id', $locality->getKey())
                    ->update([
                        'representative_point' => null,
                    ]);
            } else {
                $pointGeoJson = $this->validateRepresentativePoint(
                    $validated['representative_point']
                );

                DB::update(
                    <<<'SQL'
                    UPDATE localities
                    SET representative_point = (
                        ST_SetSRID(
                            ST_GeomFromGeoJSON(?),
                            4326
                        )
                    )::geography
                    WHERE id = ?
                    SQL,
                    [
                        $pointGeoJson,
                        $locality->getKey(),
                    ],
                );
            }
        }

        return $changed;
    }

    private function validateBoundary(
        array $boundary,
    ): string {
        $geoJson = $this->encodeGeoJson(
            $boundary,
            'boundary',
        );

        try {
            $result = DB::selectOne(
                <<<'SQL'
                SELECT
                    ST_GeometryType(geom) AS geometry_type,
                    ST_NDims(geom) AS dimensions,
                    ST_IsEmpty(geom) AS is_empty,
                    ST_IsValid(geom) AS is_valid,
                    ST_IsValidReason(geom) AS validity_reason,
                    ST_XMin(Box3D(geom)) AS min_x,
                    ST_XMax(Box3D(geom)) AS max_x,
                    ST_YMin(Box3D(geom)) AS min_y,
                    ST_YMax(Box3D(geom)) AS max_y
                FROM (
                    SELECT ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    ) AS geom
                ) AS spatial_input
                SQL,
                [
                    $geoJson,
                ],
            );
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'boundary' => [
                    'El boundary no contiene un GeoJSON valido para PostGIS.',
                ],
            ]);
        }

        if (
            $result === null
            || $result->geometry_type !== 'ST_MultiPolygon'
        ) {
            throw ValidationException::withMessages([
                'boundary' => [
                    'El boundary debe ser una geometria MultiPolygon.',
                ],
            ]);
        }

        if ((int) $result->dimensions !== 2) {
            throw ValidationException::withMessages([
                'boundary' => [
                    'El boundary debe contener exclusivamente coordenadas 2D.',
                ],
            ]);
        }

        if ((bool) $result->is_empty) {
            throw ValidationException::withMessages([
                'boundary' => [
                    'El boundary no puede ser una geometria vacia.',
                ],
            ]);
        }

        if (! (bool) $result->is_valid) {
            throw ValidationException::withMessages([
                'boundary' => [
                    'El boundary contiene una geometria invalida: '
                    . $result->validity_reason,
                ],
            ]);
        }

        if (
            (float) $result->min_x < -180
            || (float) $result->max_x > 180
            || (float) $result->min_y < -90
            || (float) $result->max_y > 90
        ) {
            throw ValidationException::withMessages([
                'boundary' => [
                    'Las coordenadas del boundary deben estar dentro de los rangos de longitud y latitud validos.',
                ],
            ]);
        }

        return $geoJson;
    }

    private function validateRepresentativePoint(
        array $representativePoint,
    ): string {
        $geoJson = $this->encodeGeoJson(
            $representativePoint,
            'representative_point',
        );

        try {
            $result = DB::selectOne(
                <<<'SQL'
                SELECT
                    ST_GeometryType(geom) AS geometry_type,
                    ST_NDims(geom) AS dimensions,
                    ST_IsEmpty(geom) AS is_empty
                FROM (
                    SELECT ST_SetSRID(
                        ST_GeomFromGeoJSON(?),
                        4326
                    ) AS geom
                ) AS spatial_input
                SQL,
                [
                    $geoJson,
                ],
            );
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                'representative_point' => [
                    'El representative_point no contiene un GeoJSON valido para PostGIS.',
                ],
            ]);
        }

        if (
            $result === null
            || $result->geometry_type !== 'ST_Point'
        ) {
            throw ValidationException::withMessages([
                'representative_point' => [
                    'El representative_point debe ser una geometria Point.',
                ],
            ]);
        }

        if ((int) $result->dimensions !== 2) {
            throw ValidationException::withMessages([
                'representative_point' => [
                    'El representative_point debe contener exclusivamente coordenadas 2D.',
                ],
            ]);
        }

        if ((bool) $result->is_empty) {
            throw ValidationException::withMessages([
                'representative_point' => [
                    'El representative_point no puede ser una geometria vacia.',
                ],
            ]);
        }

        return $geoJson;
    }

    private function encodeGeoJson(
        array $geometry,
        string $field,
    ): string {
        try {
            return json_encode(
                $geometry,
                JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                $field => [
                    'La geometria no puede convertirse a GeoJSON valido.',
                ],
            ]);
        }
    }
}