<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Geofence\StoreGeofenceRequest;
use App\Http\Requests\Geofence\UpdateGeofenceRequest;
use App\Http\Resources\GeofenceResource;
use App\Models\Geofence;
use App\Services\GeofenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GeofenceController extends Controller
{
    public function __construct(
        private readonly GeofenceService $geofenceService,
    ) {
    }

    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'type' => [
                'nullable',
                'string',
                'in:polygon,circle,corridor',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $campusId = $this->campusId($request);

        $geofences = $this->resourceQuery()
            ->where(
                'geofences.campus_id',
                $campusId
            )
            ->when(
                $filters['type'] ?? null,
                fn (
                    Builder $query,
                    string $type
                ) => $query->where(
                    'geofences.type',
                    $type
                )
            )
            ->when(
                array_key_exists(
                    'is_active',
                    $filters
                ),
                fn (Builder $query) => $query->where(
                    'geofences.is_active',
                    (bool) $filters['is_active']
                )
            )
            ->when(
                $filters['search'] ?? null,
                function (
                    Builder $query,
                    string $search
                ): void {
                    $query->where(
                        function (
                            Builder $searchQuery
                        ) use ($search): void {
                            $searchQuery
                                ->where(
                                    'geofences.name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'geofences.description',
                                    'ilike',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )
            ->orderBy('geofences.name')
            ->paginate(
                $filters['per_page'] ?? 20
            )
            ->withQueryString();

        return GeofenceResource::collection(
            $geofences
        );
    }

    public function store(
        StoreGeofenceRequest $request
    ): JsonResponse {
        $validated = $request->validated();
        $campusId = $this->campusId($request);

        $geofenceId = DB::transaction(
            function () use (
                $request,
                $validated,
                $campusId
            ): string {
                $requestedType = $validated['type'];

                /*
                 * PostgreSQL exige que una geocerca circle
                 * tenga center y radius_meters desde que
                 * type = circle.
                 *
                 * Para poder delegar la creación espacial a
                 * GeofenceService, la fila se crea dentro de
                 * esta misma transacción con un tipo temporal
                 * que no exige geometría y se transforma
                 * inmediatamente antes del COMMIT.
                 */
                $initialType = $requestedType === 'circle'
                    ? 'polygon'
                    : $requestedType;

                $geofence = Geofence::create([
                    'campus_id' => $campusId,

                    'created_by_user_id' =>
                        $request->user()?->getAuthIdentifier(),

                    'name' => $validated['name'],

                    'description' =>
                        $validated['description'] ?? null,

                    'type' => $initialType,

                    'detect_entry' =>
                        $validated['detect_entry'] ?? true,

                    'detect_exit' =>
                        $validated['detect_exit'] ?? true,

                    'schedule' =>
                        $validated['schedule'] ?? [],

                    'valid_from' =>
                        $validated['valid_from'] ?? null,

                    'valid_until' =>
                        $validated['valid_until'] ?? null,

                    'is_active' =>
                        $validated['is_active'] ?? true,
                ]);

                $this->applySpatialDefinition(
                    geofence: $geofence,
                    type: $requestedType,
                    values: $validated,
                );

                return (string) $geofence->id;
            },
            attempts: 3
        );

        $geofence = $this->findForResource(
            geofenceId: $geofenceId,
            campusId: $campusId,
        );

        return (new GeofenceResource($geofence))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Geofence $geofence,
    ): GeofenceResource {
        $campusId = $this->campusId($request);

        $this->ensureSameCampus(
            geofence: $geofence,
            campusId: $campusId,
        );

        return new GeofenceResource(
            $this->findForResource(
                geofenceId: (string) $geofence->id,
                campusId: $campusId,
            )
        );
    }

    public function update(
        UpdateGeofenceRequest $request,
        Geofence $geofence,
    ): GeofenceResource {
        $campusId = $this->campusId($request);

        $this->ensureSameCampus(
            geofence: $geofence,
            campusId: $campusId,
        );

        $validated = $request->validated();

        DB::transaction(
            function () use (
                $geofence,
                $validated
            ): void {
                $administrativeValues = Arr::except(
                    $validated,
                    [
                        'type',
                        'longitude',
                        'latitude',
                        'radius_meters',
                        'geometry',
                    ]
                );

                if ($administrativeValues !== []) {
                    $geofence->update(
                        $administrativeValues
                    );
                }

                $targetType = $validated['type']
                    ?? $geofence->type;

                $typeChanged =
                    array_key_exists(
                        'type',
                        $validated
                    )
                    && $targetType !== $geofence->type;

                $hasSpatialValues =
                    array_key_exists(
                        'longitude',
                        $validated
                    )
                    || array_key_exists(
                        'latitude',
                        $validated
                    )
                    || array_key_exists(
                        'radius_meters',
                        $validated
                    )
                    || array_key_exists(
                        'geometry',
                        $validated
                    );

                if (
                    ! $typeChanged
                    && ! $hasSpatialValues
                ) {
                    return;
                }

                /*
                 * Defensa adicional:
                 * un polygon nunca debe aceptar un radio
                 * aislado y descartarlo silenciosamente.
                 */
                if (
                    $targetType === 'polygon'
                    && array_key_exists(
                        'radius_meters',
                        $validated
                    )
                    && ! array_key_exists(
                        'geometry',
                        $validated
                    )
                ) {
                    throw ValidationException::withMessages([
                        'radius_meters' =>
                            'Una geocerca polygon no utiliza radio.',
                    ]);
                }

                $this->applySpatialDefinition(
                    geofence: $geofence,
                    type: $targetType,
                    values: $validated,
                );
            },
            attempts: 3
        );

        return new GeofenceResource(
            $this->findForResource(
                geofenceId: (string) $geofence->id,
                campusId: $campusId,
            )
        );
    }

    public function destroy(
        Request $request,
        Geofence $geofence,
    ): Response|JsonResponse {
        $campusId = $this->campusId($request);

        $this->ensureSameCampus(
            geofence: $geofence,
            campusId: $campusId,
        );

        DB::transaction(
            function () use ($geofence): void {
                $geofence->delete();
            },
            attempts: 3
        );

        return response()->noContent();
    }

    private function applySpatialDefinition(
        Geofence $geofence,
        string $type,
        array $values,
    ): void {
        if ($type === 'circle') {
            if (
                ! array_key_exists(
                    'longitude',
                    $values
                )
                || ! array_key_exists(
                    'latitude',
                    $values
                )
                || ! array_key_exists(
                    'radius_meters',
                    $values
                )
            ) {
                throw ValidationException::withMessages([
                    'geometry' =>
                        'Una geocerca circle requiere longitud, latitud y radio.',
                ]);
            }

            $this->geofenceService->setCircle(
                geofence: $geofence,
                longitude: (float) $values['longitude'],
                latitude: (float) $values['latitude'],
                radiusMeters:
                    (float) $values['radius_meters'],
            );

            return;
        }

        if ($type === 'polygon') {
            if (
                ! array_key_exists(
                    'geometry',
                    $values
                )
            ) {
                throw ValidationException::withMessages([
                    'geometry' =>
                        'Una geocerca polygon requiere geometría GeoJSON.',
                ]);
            }

            $this->geofenceService->setPolygon(
                geofence: $geofence,
                geoJson: $values['geometry'],
            );

            return;
        }

        if ($type === 'corridor') {
            if (
                ! array_key_exists(
                    'geometry',
                    $values
                )
                || ! array_key_exists(
                    'radius_meters',
                    $values
                )
            ) {
                throw ValidationException::withMessages([
                    'geometry' =>
                        'Una geocerca corridor requiere geometría GeoJSON y radio.',
                ]);
            }

            $this->geofenceService->setCorridor(
                geofence: $geofence,
                lineGeoJson: $values['geometry'],
                bufferMeters:
                    (float) $values['radius_meters'],
            );

            return;
        }

        throw ValidationException::withMessages([
            'type' =>
                'El tipo de geocerca no es válido.',
        ]);
    }

    private function resourceQuery(): Builder
    {
        return Geofence::query()
            ->select('geofences.*')
            ->selectRaw(
                <<<'SQL'
                    ST_AsGeoJSON(
                        geofences.effective_area
                    ) AS geometry_geojson
                SQL
            )
            ->selectRaw(
                <<<'SQL'
                    ST_X(
                        geofences.center::geometry
                    ) AS center_longitude
                SQL
            )
            ->selectRaw(
                <<<'SQL'
                    ST_Y(
                        geofences.center::geometry
                    ) AS center_latitude
                SQL
            )
            ->with([
                'campus:id,code,name',
                'creator:id,name',
            ]);
    }

    private function findForResource(
        string $geofenceId,
        string $campusId,
    ): Geofence {
        return $this->resourceQuery()
            ->where(
                'geofences.id',
                $geofenceId
            )
            ->where(
                'geofences.campus_id',
                $campusId
            )
            ->firstOrFail();
    }

    private function campusId(
        Request $request
    ): string {
        $campusId = (string) $request->attributes->get(
            'campus_id'
        );

        if ($campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' =>
                    'No se proporcionó un contexto de plantel válido.',
            ]);
        }

        return $campusId;
    }

    private function ensureSameCampus(
        Geofence $geofence,
        string $campusId,
    ): void {
        if ($geofence->campus_id !== $campusId) {
            abort(404);
        }
    }
}