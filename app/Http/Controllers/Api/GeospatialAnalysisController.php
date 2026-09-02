<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeospatialAnalysis\IndexLocalityChoroplethRequest;
use App\Http\Requests\GeospatialAnalysis\IndexStudentHeatmapRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

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
        $campusId = $this->getCampusId($request);

        $latestPositions = DB::table('student_positions')
            ->whereNotNull(
                'student_positions.location'
            )
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
                            'captured_at' => $position->captured_at,
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
}