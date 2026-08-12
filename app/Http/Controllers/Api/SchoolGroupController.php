<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SchoolGroup\StoreSchoolGroupRequest;
use App\Http\Requests\SchoolGroup\UpdateSchoolGroupRequest;
use App\Http\Resources\SchoolGroupResource;
use App\Models\SchoolGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchoolGroupController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'school_cycle_id' => [
                'nullable',
                'uuid',
            ],
            'grade_level' => [
                'nullable',
                'string',
                'max:30',
            ],
            'shift' => [
                'nullable',
                Rule::in([
                    'morning',
                    'afternoon',
                    'evening',
                    'full_time',
                ]),
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'between:1,100',
            ],
        ]);

        $campusId = $this->campusId($request);

        $groups = SchoolGroup::query()
            ->with([
                'schoolCycle:id,campus_id,name,starts_on,ends_on,status,is_current',
            ])
            ->withCount([
                'enrollments',
                'teachingAssignments',
            ])
            ->whereHas(
                'schoolCycle',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->when(
                $filters['school_cycle_id'] ?? null,
                fn ($query, string $schoolCycleId) =>
                    $query->where(
                        'school_cycle_id',
                        $schoolCycleId
                    )
            )
            ->when(
                $filters['grade_level'] ?? null,
                fn ($query, string $gradeLevel) =>
                    $query->where(
                        'grade_level',
                        $gradeLevel
                    )
            )
            ->when(
                $filters['shift'] ?? null,
                fn ($query, string $shift) =>
                    $query->where('shift', $shift)
            )
            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where(
                    'is_active',
                    $filters['is_active']
                )
            )
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search): void {
                    $query->where(
                        function ($searchQuery) use ($search): void {
                            $searchQuery
                                ->where(
                                    'grade_level',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'section',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'classroom',
                                    'ilike',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )
            ->orderBy('school_cycle_id')
            ->orderBy('grade_level')
            ->orderBy('section')
            ->orderBy('shift')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return SchoolGroupResource::collection($groups);
    }

    public function store(
        StoreSchoolGroupRequest $request
    ): JsonResponse {
        try {
            $schoolGroup = SchoolGroup::create(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'section' => [
                    'Ya existe un grupo con el mismo ciclo, grado, sección y turno.',
                ],
            ]);
        }

        $schoolGroup->load([
            'schoolCycle:id,campus_id,name,starts_on,ends_on,status,is_current',
        ]);

        $schoolGroup->loadCount([
            'enrollments',
            'teachingAssignments',
        ]);

        return (new SchoolGroupResource($schoolGroup))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        SchoolGroup $schoolGroup
    ): SchoolGroupResource {
        $this->ensureGroupBelongsToCampus(
            $request,
            $schoolGroup
        );

        $schoolGroup->load([
            'schoolCycle:id,campus_id,name,starts_on,ends_on,status,is_current',
        ]);

        $schoolGroup->loadCount([
            'enrollments',
            'teachingAssignments',
        ]);

        return new SchoolGroupResource($schoolGroup);
    }

    public function update(
        UpdateSchoolGroupRequest $request,
        SchoolGroup $schoolGroup
    ): SchoolGroupResource {
        $this->ensureGroupBelongsToCampus(
            $request,
            $schoolGroup
        );

        try {
            $schoolGroup->update(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'section' => [
                    'Ya existe otro grupo con el mismo ciclo, grado, sección y turno.',
                ],
            ]);
        }

        $schoolGroup->refresh();

        $schoolGroup->load([
            'schoolCycle:id,campus_id,name,starts_on,ends_on,status,is_current',
        ]);

        $schoolGroup->loadCount([
            'enrollments',
            'teachingAssignments',
        ]);

        return new SchoolGroupResource($schoolGroup);
    }

    public function destroy(
        Request $request,
        SchoolGroup $schoolGroup
    ): Response|JsonResponse {
        $this->ensureGroupBelongsToCampus(
            $request,
            $schoolGroup
        );

        if ($schoolGroup->enrollments()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un grupo que tiene inscripciones. Desactívalo en su lugar.',
            ], 409);
        }

        if ($schoolGroup->teachingAssignments()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un grupo que tiene asignaciones docentes. Desactívalo en su lugar.',
            ], 409);
        }

        if ($schoolGroup->attendanceSessions()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un grupo que tiene sesiones de asistencia. Desactívalo en su lugar.',
            ], 409);
        }

        $schoolGroup->delete();

        return response()->noContent();
    }

    private function campusId(Request $request): string
    {
        $campusId = $request->attributes->get(
            'campus_id'
        );

        abort_if(
            ! is_string($campusId) || $campusId === '',
            400,
            'No se proporcionó un contexto de plantel válido.'
        );

        return $campusId;
    }

    private function ensureGroupBelongsToCampus(
        Request $request,
        SchoolGroup $schoolGroup
    ): void {
        $campusId = $this->campusId($request);

        $schoolGroup->loadMissing(
            'schoolCycle:id,campus_id'
        );

        /*
         * Se devuelve 404 para no revelar la existencia
         * de grupos pertenecientes a otros planteles.
         */
        abort_unless(
            $schoolGroup->schoolCycle
                && $schoolGroup->schoolCycle->campus_id
                    === $campusId,
            404,
            'Grupo escolar no encontrado.'
        );
    }
}