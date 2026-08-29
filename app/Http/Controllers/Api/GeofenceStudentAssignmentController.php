<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeofenceStudentAssignment\IndexGeofenceStudentAssignmentRequest;
use App\Http\Requests\GeofenceStudentAssignment\StoreGeofenceStudentAssignmentRequest;
use App\Http\Requests\GeofenceStudentAssignment\UpdateGeofenceStudentAssignmentRequest;
use App\Http\Resources\GeofenceStudentAssignmentResource;
use App\Models\Geofence;
use App\Models\GeofenceStudentAssignment;
use App\Models\Student;
use App\Services\GeofenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GeofenceStudentAssignmentController extends Controller
{
    public function __construct(
        private readonly GeofenceService $geofenceService,
    ) {
    }

    public function index(
        IndexGeofenceStudentAssignmentRequest $request,
        Geofence $geofence,
    ): JsonResponse {
        $campusId = $this->campusId($request);

        $this->ensureGeofenceBelongsToCampus(
            $geofence,
            $campusId,
        );

        $validated = $request->validated();
        $limit = (int) ($validated['limit'] ?? 100);

        $query = GeofenceStudentAssignment::query()
            ->where('geofence_id', $geofence->id);

        if (isset($validated['student_id'])) {
            $query->where(
                'student_id',
                $validated['student_id'],
            );
        }

        if (array_key_exists('is_active', $validated)) {
            $query->where(
                'is_active',
                $request->boolean('is_active'),
            );
        }

        $assignments = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => GeofenceStudentAssignmentResource::collection(
                $assignments
            ),
            'meta' => [
                'limit' => $limit,
                'count' => $assignments->count(),
            ],
        ]);
    }

    public function store(
        StoreGeofenceStudentAssignmentRequest $request,
        Geofence $geofence,
    ): JsonResponse {
        $campusId = $this->campusId($request);

        $this->ensureGeofenceBelongsToCampus(
            $geofence,
            $campusId,
        );

        $validated = $request->validated();

        $student = Student::query()
            ->where('id', $validated['student_id'])
            ->where('campus_id', $campusId)
            ->first();

        if (! $student) {
            throw ValidationException::withMessages([
                'student_id' => [
                    'El alumno indicado no pertenece al plantel actual.',
                ],
            ]);
        }

        $startsAt = CarbonImmutable::parse(
            $validated['starts_at']
        );

        $endsAt = isset($validated['ends_at'])
            ? CarbonImmutable::parse($validated['ends_at'])
            : null;

        $assignment = $this->geofenceService->assignStudent(
            geofence: $geofence,
            student: $student,
            consentReference: $validated['consent_reference'],
            startsAt: $startsAt,
            endsAt: $endsAt,
            authorizedBy: $request->user(),
            notificationSettings: $validated['notification_settings'] ?? [],
        );

        return response()->json([
            'data' => new GeofenceStudentAssignmentResource(
                $assignment
            ),
        ], 201);
    }

    public function update(
        UpdateGeofenceStudentAssignmentRequest $request,
        Geofence $geofence,
        Student $student,
    ): JsonResponse {
        $campusId = $this->campusId($request);

        $this->ensureGeofenceBelongsToCampus(
            $geofence,
            $campusId,
        );

        $this->ensureStudentBelongsToCampus(
            $student,
            $campusId,
        );

        $validated = $request->validated();

        if ($validated === []) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'Debe proporcionar al menos un campo para actualizar.',
                ],
            ]);
        }

        $assignment = DB::transaction(function () use (
            $validated,
            $geofence,
            $student,
        ): GeofenceStudentAssignment {
            $assignment = GeofenceStudentAssignment::query()
                ->where('geofence_id', $geofence->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                abort(
                    404,
                    'No existe una asignación activa entre la geocerca y el alumno indicados.'
                );
            }

            $startsAt = array_key_exists(
                'starts_at',
                $validated
            )
                ? CarbonImmutable::parse(
                    $validated['starts_at']
                )
                : CarbonImmutable::parse(
                    $assignment->starts_at
                );

            if (array_key_exists('ends_at', $validated)) {
                $endsAt = $validated['ends_at'] !== null
                    ? CarbonImmutable::parse(
                        $validated['ends_at']
                    )
                    : null;
            } else {
                $endsAt = $assignment->ends_at !== null
                    ? CarbonImmutable::parse(
                        $assignment->ends_at
                    )
                    : null;
            }

            if (
                $endsAt !== null
                && $endsAt->lt($startsAt)
            ) {
                throw ValidationException::withMessages([
                    'ends_at' => [
                        'La fecha final debe ser igual o posterior a la fecha inicial.',
                    ],
                ]);
            }

            if (array_key_exists(
                'consent_reference',
                $validated
            )) {
                $consentReference = trim(
                    $validated['consent_reference']
                );

                if ($consentReference === '') {
                    throw ValidationException::withMessages([
                        'consent_reference' => [
                            'La referencia de autorización no puede estar vacía.',
                        ],
                    ]);
                }

                $assignment->consent_reference =
                    $consentReference;
            }

            if (array_key_exists('starts_at', $validated)) {
                $assignment->starts_at = $startsAt;
            }

            if (array_key_exists('ends_at', $validated)) {
                $assignment->ends_at = $endsAt;
            }

            if (array_key_exists(
                'notification_settings',
                $validated
            )) {
                $currentSettings = is_array(
                    $assignment->notification_settings
                )
                    ? $assignment->notification_settings
                    : [];

                $assignment->notification_settings =
                    array_replace(
                        $currentSettings,
                        $validated['notification_settings'],
                    );
            }

            if (array_key_exists('is_active', $validated)) {
                $assignment->is_active = (bool) $validated[
                    'is_active'
                ];
            }

            $assignment->save();

            return $assignment->refresh();
        });

        return response()->json([
            'data' => new GeofenceStudentAssignmentResource(
                $assignment
            ),
        ]);
    }

    public function destroy(
        Request $request,
        Geofence $geofence,
        Student $student,
    ): JsonResponse {
        $campusId = $this->campusId($request);

        $this->ensureGeofenceBelongsToCampus(
            $geofence,
            $campusId,
        );

        $this->ensureStudentBelongsToCampus(
            $student,
            $campusId,
        );

        DB::transaction(function () use (
            $geofence,
            $student,
        ): void {
            $assignment = GeofenceStudentAssignment::query()
                ->where('geofence_id', $geofence->id)
                ->where('student_id', $student->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $assignment) {
                abort(
                    404,
                    'No existe una asignación activa entre la geocerca y el alumno indicados.'
                );
            }

            $startsAt = CarbonImmutable::parse(
                $assignment->starts_at
            );

            $now = CarbonImmutable::now();

            $assignment->is_active = false;
            $assignment->ends_at = $now->lt($startsAt)
                ? $startsAt
                : $now;

            $assignment->save();
        });

        return response()->json([
            'message' =>
                'La asignación de la geocerca al alumno fue desactivada correctamente.',
        ]);
    }

    private function campusId(Request $request): string
    {
        $campusId = $request->attributes->get('campus_id');

        if (! is_string($campusId) || $campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => [
                    'No fue posible determinar el contexto del plantel.',
                ],
            ]);
        }

        return $campusId;
    }

    private function ensureGeofenceBelongsToCampus(
        Geofence $geofence,
        string $campusId,
    ): void {
        if ($geofence->campus_id !== $campusId) {
            abort(
                404,
                'La geocerca indicada no pertenece al plantel actual.'
            );
        }
    }

    private function ensureStudentBelongsToCampus(
        Student $student,
        string $campusId,
    ): void {
        if ($student->campus_id !== $campusId) {
            abort(
                404,
                'El alumno indicado no pertenece al plantel actual.'
            );
        }
    }
}