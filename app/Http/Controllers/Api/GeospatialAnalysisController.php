<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeospatialAnalysis\IndexLocalityChoroplethRequest;
use App\Http\Requests\GeospatialAnalysis\IndexStudentDensityGridRequest;
use App\Http\Requests\GeospatialAnalysis\IndexStudentHeatmapRequest;
use App\Http\Requests\GeospatialAnalysis\IndexStudentInfluenceZoneRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class GeospatialAnalysisController extends Controller
{
    public function localityChoropleth(
        IndexLocalityChoroplethRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $campusId = $this->getCampusId($request);

        $includeGeometry = array_key_exists(
            'include_geometry',
            $validated,
        )
            ? $request->boolean('include_geometry')
            : true;

        $studentCounts = DB::table('student_addresses')
            ->join(
                'students',
                'students.id',
                '=',
                'student_addresses.student_id',
            )
            ->where(
                'students.campus_id',
                $campusId,
            )
            ->where(
                'students.status',
                'active',
            )
            ->whereNull(
                'students.deleted_at'
            )
            ->whereNull(
                'student_addresses.deleted_at'
            )
            ->where(
                'student_addresses.is_primary',
                true,
            )
            ->whereNotNull(
                'student_addresses.locality_id'
            )
            ->groupBy(
                'student_addresses.locality_id'
            )
            ->selectRaw(
                'student_addresses.locality_id'
            )
            ->selectRaw(
                'COUNT(DISTINCT students.id) AS student_count'
            );

        $query = DB::table('localities')
            ->leftJoinSub(
                $studentCounts,
                'student_counts',
                function ($join): void {
                    $join->on(
                        'student_counts.locality_id',
                        '=',
                        'localities.id',
                    );
                },
            )
            ->where(
                'localities.is_active',
                true,
            )
            ->select([
                'localities.id',
                'localities.parent_id',
                'localities.official_code',
                'localities.name',
                'localities.type',
                'localities.population',
                'localities.area_square_km',
            ])
            ->selectRaw(
                'COALESCE(student_counts.student_count, 0) AS student_count'
            );

        $this->applyLocalityFilters(
            $query,
            $validated,
        );

        if ($includeGeometry) {
            $query->selectRaw(
                'ST_AsGeoJSON(localities.boundary) AS boundary_geojson'
            );
        }

        $localities = $query
            ->orderBy('localities.type')
            ->orderBy('localities.name')
            ->get();

        $features = $localities
            ->map(
                function ($locality) use (
                    $includeGeometry,
                ): array {
                    return [
                        'type' => 'Feature',

                        'id' => $locality->id,

                        'geometry' => $includeGeometry
                            ? $this->decodeGeoJson(
                                $locality->boundary_geojson
                                    ?? null
                            )
                            : null,

                        'properties' => [
                            'id' => $locality->id,
                            'parent_id' => $locality->parent_id,
                            'official_code' => $locality->official_code,
                            'name' => $locality->name,
                            'type' => $locality->type,
                            'population' => $locality->population !== null
                                ? (int) $locality->population
                                : null,
                            'area_square_km' => $locality->area_square_km !== null
                                ? (float) $locality->area_square_km
                                : null,
                            'student_count' => (int) $locality->student_count,
                        ],
                    ];
                }
            )
            ->values();

        $totalStudents = $features->sum(
            fn (array $feature): int =>
                $feature['properties']['student_count']
        );

        $localitiesWithStudents = $features->filter(
            fn (array $feature): bool =>
                $feature['properties']['student_count'] > 0
        )->count();

        return response()->json([
            'data' => [
                'type' => 'FeatureCollection',
                'features' => $features->all(),
            ],

            'meta' => [
                'campus_id' => $campusId,
                'locality_count' => $features->count(),
                'localities_with_students' =>
                    $localitiesWithStudents,
                'student_count' => $totalStudents,
                'include_geometry' => $includeGeometry,
            ],
        ]);
    }

    public function studentHeatmap(
        IndexStudentHeatmapRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $campusId = $this->getCampusId($request);

        $maxAgeMinutes = isset(
            $validated['max_age_minutes']
        )
            ? (int) $validated['max_age_minutes']
            : null;

        $positionCutoff = $maxAgeMinutes !== null
            ? now()->subMinutes($maxAgeMinutes)
            : null;

        $latestPositions = DB::table('student_positions')
            ->whereNotNull(
                'student_positions.location'
            );

        if ($positionCutoff !== null) {
            $latestPositions->where(
                'student_positions.captured_at',
                '>=',
                $positionCutoff,
            );
        }

        $latestPositions
            ->selectRaw(
                'DISTINCT ON (student_positions.student_id)
                student_positions.id,
                student_positions.student_id,
                student_positions.captured_at,
                student_positions.location'
            )
            ->orderBy(
                'student_positions.student_id'
            )
            ->orderByDesc(
                'student_positions.captured_at'
            )
            ->orderByDesc(
                'student_positions.id'
            );

        $positions = DB::query()
            ->fromSub(
                $latestPositions,
                'latest_positions',
            )
            ->join(
                'students',
                'students.id',
                '=',
                'latest_positions.student_id',
            )
            ->where(
                'students.campus_id',
                $campusId,
            )
            ->where(
                'students.status',
                'active',
            )
            ->whereNull(
                'students.deleted_at'
            )
            ->select([
                'latest_positions.id',
                'latest_positions.student_id',
                'latest_positions.captured_at',
            ])
            ->selectRaw(
                'ST_AsGeoJSON(
                    latest_positions.location::geometry
                ) AS location_geojson'
            )
            ->orderBy(
                'latest_positions.student_id'
            )
            ->get();

        $features = $positions
            ->map(
                function ($position): array {
                    return [
                        'type' => 'Feature',

                        'id' => $position->id,

                        'geometry' => $this->decodeGeoJson(
                            $position->location_geojson
                                ?? null
                        ),

                        'properties' => [
                            'position_id' => $position->id,
                            'student_id' => $position->student_id,
                            'captured_at' => $this->toIso8601Utc(
                                $position->captured_at
                            ),
                            'weight' => 1,
                        ],
                    ];
                }
            )
            ->values();

        return response()->json([
            'data' => [
                'type' => 'FeatureCollection',
                'features' => $features->all(),
            ],

            'meta' => [
                'campus_id' => $campusId,
                'student_count' => $features->count(),
                'point_count' => $features->count(),
                'weight_total' => $features->sum(
                    fn (array $feature): int =>
                        $feature['properties']['weight']
                ),
                'position_strategy' => 'latest_per_student',
                'max_age_minutes' => $maxAgeMinutes,
            ],
        ]);
    }

    public function studentDensityGrid(
        IndexStudentDensityGridRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $campusId = $this->getCampusId($request);

        $cellSizeMeters = isset(
            $validated['cell_size_meters']
        )
            ? (int) $validated['cell_size_meters']
            : 500;

        $maxAgeMinutes = isset(
            $validated['max_age_minutes']
        )
            ? (int) $validated['max_age_minutes']
            : null;

        $positionCutoff = $maxAgeMinutes !== null
            ? now()->subMinutes($maxAgeMinutes)
            : null;

        $latestPositions = DB::table('student_positions')
            ->whereNotNull(
                'student_positions.location'
            );

        if ($positionCutoff !== null) {
            $latestPositions->where(
                'student_positions.captured_at',
                '>=',
                $positionCutoff,
            );
        }

        $latestPositions
            ->selectRaw(
                'DISTINCT ON (student_positions.student_id)
                student_positions.id,
                student_positions.student_id,
                student_positions.captured_at,
                student_positions.location'
            )
            ->orderBy(
                'student_positions.student_id'
            )
            ->orderByDesc(
                'student_positions.captured_at'
            )
            ->orderByDesc(
                'student_positions.id'
            );

        $campusPositions = DB::query()
            ->fromSub(
                $latestPositions,
                'latest_positions',
            )
            ->join(
                'students',
                'students.id',
                '=',
                'latest_positions.student_id',
            )
            ->where(
                'students.campus_id',
                $campusId,
            )
            ->where(
                'students.status',
                'active',
            )
            ->whereNull(
                'students.deleted_at'
            )
            ->select([
                'latest_positions.student_id',
                'latest_positions.location',
            ]);

        $referencePoint = DB::query()
            ->fromSub(
                clone $campusPositions,
                'campus_positions',
            )
            ->selectRaw(
                'AVG(
                    ST_X(
                        campus_positions.location::geometry
                    )
                ) AS longitude'
            )
            ->selectRaw(
                'AVG(
                    ST_Y(
                        campus_positions.location::geometry
                    )
                ) AS latitude'
            )
            ->first();

        $referenceLongitude = $referencePoint?->longitude !== null
            ? (float) $referencePoint->longitude
            : null;

        $referenceLatitude = $referencePoint?->latitude !== null
            ? (float) $referencePoint->latitude
            : null;

        $metricSrid = null;

        if (
            $referenceLongitude !== null
            && $referenceLatitude !== null
        ) {
            $metricSrid = $this->getUtmSrid(
                $referenceLongitude,
                $referenceLatitude,
            );
        }

        $cells = collect();

        if ($metricSrid !== null) {
            $projectedPositions = DB::query()
                ->fromSub(
                    $campusPositions,
                    'campus_positions',
                )
                ->select([
                    'campus_positions.student_id',
                ])
                ->selectRaw(
                    'ST_Transform(
                        campus_positions.location::geometry,
                        CAST(? AS integer)
                    ) AS projected_location',
                    [
                        $metricSrid,
                    ]
                );

            $gridCoordinates = DB::query()
                ->fromSub(
                    $projectedPositions,
                    'projected_positions',
                )
                ->select([
                    'projected_positions.student_id',
                ])
                ->selectRaw(
                    'FLOOR(
                        ST_X(
                            projected_positions.projected_location
                        ) / ?
                    ) * ? AS grid_x',
                    [
                        $cellSizeMeters,
                        $cellSizeMeters,
                    ]
                )
                ->selectRaw(
                    'FLOOR(
                        ST_Y(
                            projected_positions.projected_location
                        ) / ?
                    ) * ? AS grid_y',
                    [
                        $cellSizeMeters,
                        $cellSizeMeters,
                    ]
                );

            $cells = DB::query()
                ->fromSub(
                    $gridCoordinates,
                    'grid_coordinates',
                )
                ->groupBy([
                    'grid_coordinates.grid_x',
                    'grid_coordinates.grid_y',
                ])
                ->select([
                    'grid_coordinates.grid_x',
                    'grid_coordinates.grid_y',
                ])
                ->selectRaw(
                    'COUNT(
                        DISTINCT grid_coordinates.student_id
                    ) AS student_count'
                )
                ->selectRaw(
                    'ST_AsGeoJSON(
                        ST_Transform(
                            ST_MakeEnvelope(
                                grid_coordinates.grid_x,
                                grid_coordinates.grid_y,
                                grid_coordinates.grid_x + ?,
                                grid_coordinates.grid_y + ?,
                                CAST(? AS integer)
                            ),
                            4326
                        )
                    ) AS cell_geojson',
                    [
                        $cellSizeMeters,
                        $cellSizeMeters,
                        $metricSrid,
                    ]
                )
                ->orderByDesc('student_count')
                ->orderBy('grid_coordinates.grid_x')
                ->orderBy('grid_coordinates.grid_y')
                ->get();
        }

        $features = $cells
            ->map(
                function ($cell, int $index): array {
                    return [
                        'type' => 'Feature',

                        'id' => 'cell-' . ($index + 1),

                        'geometry' => $this->decodeGeoJson(
                            $cell->cell_geojson
                                ?? null
                        ),

                        'properties' => [
                            'student_count' =>
                                (int) $cell->student_count,
                            'weight' =>
                                (int) $cell->student_count,
                        ],
                    ];
                }
            )
            ->values();

        $studentCount = $features->sum(
            fn (array $feature): int =>
                $feature['properties']['student_count']
        );

        $maximumCellCount = $features->max(
            fn (array $feature): int =>
                $feature['properties']['student_count']
        ) ?? 0;

        return response()->json([
            'data' => [
                'type' => 'FeatureCollection',
                'features' => $features->all(),
            ],

            'meta' => [
                'campus_id' => $campusId,
                'cell_size_meters' => $cellSizeMeters,
                'cell_count' => $features->count(),
                'student_count' => $studentCount,
                'maximum_cell_count' => $maximumCellCount,
                'position_strategy' => 'latest_per_student',
                'max_age_minutes' => $maxAgeMinutes,
                'grid_strategy' => 'dynamic_utm',
                'metric_projection' => $metricSrid !== null
                    ? 'EPSG:' . $metricSrid
                    : null,
                'output_projection' => 'EPSG:4326',
            ],
        ]);
    }

    public function studentInfluenceZones(
        IndexStudentInfluenceZoneRequest $request,
    ): JsonResponse {
        $validated = $request->validated();

        $campusId = $this->getCampusId($request);

        $radiusMeters = isset(
            $validated['radius_meters']
        )
            ? (int) $validated['radius_meters']
            : 1000;

        $maxAgeMinutes = isset(
            $validated['max_age_minutes']
        )
            ? (int) $validated['max_age_minutes']
            : null;

        $positionCutoff = $maxAgeMinutes !== null
            ? now()->subMinutes($maxAgeMinutes)
            : null;

        $latestPositions = DB::table('student_positions')
            ->whereNotNull(
                'student_positions.location'
            );

        if ($positionCutoff !== null) {
            $latestPositions->where(
                'student_positions.captured_at',
                '>=',
                $positionCutoff,
            );
        }

        $latestPositions
            ->selectRaw(
                'DISTINCT ON (student_positions.student_id)
                student_positions.id,
                student_positions.student_id,
                student_positions.captured_at,
                student_positions.location'
            )
            ->orderBy(
                'student_positions.student_id'
            )
            ->orderByDesc(
                'student_positions.captured_at'
            )
            ->orderByDesc(
                'student_positions.id'
            );

        $positions = DB::query()
            ->fromSub(
                $latestPositions,
                'latest_positions',
            )
            ->join(
                'students',
                'students.id',
                '=',
                'latest_positions.student_id',
            )
            ->where(
                'students.campus_id',
                $campusId,
            )
            ->where(
                'students.status',
                'active',
            )
            ->whereNull(
                'students.deleted_at'
            )
            ->select([
                'latest_positions.id',
                'latest_positions.student_id',
                'latest_positions.captured_at',
            ])
            ->selectRaw(
                'ST_AsGeoJSON(
                    ST_Buffer(
                        latest_positions.location,
                        ?
                    )::geometry
                ) AS influence_zone_geojson',
                [
                    $radiusMeters,
                ]
            )
            ->orderBy(
                'latest_positions.student_id'
            )
            ->get();

        $features = $positions
            ->map(
                function ($position) use (
                    $radiusMeters,
                ): array {
                    return [
                        'type' => 'Feature',

                        'id' => $position->id,

                        'geometry' => $this->decodeGeoJson(
                            $position->influence_zone_geojson
                                ?? null
                        ),

                        'properties' => [
                            'position_id' => $position->id,
                            'student_id' => $position->student_id,
                            'captured_at' => $this->toIso8601Utc(
                                $position->captured_at
                            ),
                            'radius_meters' => $radiusMeters,
                        ],
                    ];
                }
            )
            ->values();

        return response()->json([
            'data' => [
                'type' => 'FeatureCollection',
                'features' => $features->all(),
            ],

            'meta' => [
                'campus_id' => $campusId,
                'radius_meters' => $radiusMeters,
                'student_count' => $features->count(),
                'zone_count' => $features->count(),
                'position_strategy' => 'latest_per_student',
                'max_age_minutes' => $maxAgeMinutes,
                'buffer_strategy' => 'geography',
                'output_projection' => 'EPSG:4326',
            ],
        ]);
    }

    private function getCampusId(
        FormRequest $request,
    ): string {
        $campusId = (string) $request->attributes->get(
            'campus_id'
        );

        if ($campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => [
                    'No se proporciono un contexto de plantel valido.',
                ],
            ]);
        }

        return $campusId;
    }

    private function applyLocalityFilters(
        Builder $query,
        array $validated,
    ): void {
        if (array_key_exists('parent_id', $validated)) {
            if ($validated['parent_id'] === null) {
                $query->whereNull(
                    'localities.parent_id'
                );
            } else {
                $query->where(
                    'localities.parent_id',
                    $validated['parent_id'],
                );
            }
        }

        if (isset($validated['type'])) {
            $query->where(
                'localities.type',
                $validated['type'],
            );
        }
    }

    private function decodeGeoJson(
        ?string $geoJson,
    ): ?array {
        if ($geoJson === null || $geoJson === '') {
            return null;
        }

        try {
            return json_decode(
                $geoJson,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            return null;
        }
    }

    private function toIso8601Utc(
        mixed $dateTime,
    ): ?string {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                (string) $dateTime
            )
                ->utc()
                ->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function getUtmSrid(
        float $longitude,
        float $latitude,
    ): int {
        $normalizedLongitude = max(
            -180.0,
            min(179.999999, $longitude),
        );

        $zone = (int) floor(
            ($normalizedLongitude + 180.0) / 6.0
        ) + 1;

        $zone = max(
            1,
            min(60, $zone),
        );

        return $latitude >= 0.0
            ? 32600 + $zone
            : 32700 + $zone;
    }
}