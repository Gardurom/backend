<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeachingAssignment\StoreTeachingAssignmentRequest;
use App\Http\Requests\TeachingAssignment\UpdateTeachingAssignmentRequest;
use App\Http\Resources\TeachingAssignmentResource;
use App\Models\TeachingAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeachingAssignmentController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'school_group_id' => [
                'nullable',
                'uuid',
            ],
            'subject_id' => [
                'nullable',
                'uuid',
            ],
            'teacher_id' => [
                'nullable',
                'uuid',
            ],
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'active',
                    'completed',
                    'cancelled',
                ]),
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

        $assignments = TeachingAssignment::query()
            ->with([
                'schoolGroup.schoolCycle:id,campus_id,name',
                'subject:id,campus_id,code,name,weekly_hours',
                'teacher.person',
            ])
            ->withCount([
                'assessments',
                'attendanceSessions',
            ])
            ->whereHas(
                'schoolGroup.schoolCycle',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->whereHas(
                'subject',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->whereHas(
                'teacher',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->when(
                $filters['school_group_id'] ?? null,
                fn ($query, string $schoolGroupId) =>
                    $query->where(
                        'school_group_id',
                        $schoolGroupId
                    )
            )
            ->when(
                $filters['subject_id'] ?? null,
                fn ($query, string $subjectId) =>
                    $query->where(
                        'subject_id',
                        $subjectId
                    )
            )
            ->when(
                $filters['teacher_id'] ?? null,
                fn ($query, string $teacherId) =>
                    $query->where(
                        'teacher_id',
                        $teacherId
                    )
            )
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) =>
                    $query->where(
                        'status',
                        $status
                    )
            )
            ->when(
                $filters['search'] ?? null,
                function (
                    $query,
                    string $search
                ): void {
                    $query->where(
                        function ($searchQuery) use (
                            $search
                        ): void {
                            $searchQuery
                                ->whereHas(
                                    'subject',
                                    function ($subjectQuery) use (
                                        $search
                                    ): void {
                                        $subjectQuery
                                            ->where(
                                                'code',
                                                'ilike',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'name',
                                                'ilike',
                                                "%{$search}%"
                                            );
                                    }
                                )
                                ->orWhereHas(
                                    'teacher.person',
                                    function ($personQuery) use (
                                        $search
                                    ): void {
                                        $personQuery
                                            ->where(
                                                'first_name',
                                                'ilike',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'middle_name',
                                                'ilike',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'paternal_surname',
                                                'ilike',
                                                "%{$search}%"
                                            )
                                            ->orWhere(
                                                'maternal_surname',
                                                'ilike',
                                                "%{$search}%"
                                            );
                                    }
                                )
                                ->orWhereHas(
                                    'teacher',
                                    fn ($teacherQuery) =>
                                        $teacherQuery->where(
                                            'employee_number',
                                            'ilike',
                                            "%{$search}%"
                                        )
                                )
                                ->orWhereHas(
                                    'schoolGroup',
                                    function ($groupQuery) use (
                                        $search
                                    ): void {
                                        $groupQuery
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
                    );
                }
            )
            ->orderByDesc('starts_on')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TeachingAssignmentResource::collection(
            $assignments
        );
    }

    public function store(
        StoreTeachingAssignmentRequest $request
    ): JsonResponse {
        try {
            $assignment = TeachingAssignment::create(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'Esta combinación de grupo, materia y profesor ya tiene una asignación docente.',
                ],
            ]);
        }

        $this->loadRelations($assignment);

        return (new TeachingAssignmentResource(
            $assignment
        ))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        TeachingAssignment $teachingAssignment
    ): TeachingAssignmentResource {
        $this->ensureAssignmentBelongsToCampus(
            $request,
            $teachingAssignment
        );

        $this->loadRelations($teachingAssignment);

        return new TeachingAssignmentResource(
            $teachingAssignment
        );
    }

    public function update(
        UpdateTeachingAssignmentRequest $request,
        TeachingAssignment $teachingAssignment
    ): TeachingAssignmentResource {
        $this->ensureAssignmentBelongsToCampus(
            $request,
            $teachingAssignment
        );

        try {
            $teachingAssignment->update(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'teacher_id' => [
                    'Esta combinación de grupo, materia y profesor ya tiene una asignación docente.',
                ],
            ]);
        }

        $teachingAssignment->refresh();

        $this->loadRelations($teachingAssignment);

        return new TeachingAssignmentResource(
            $teachingAssignment
        );
    }

    public function destroy(
        Request $request,
        TeachingAssignment $teachingAssignment
    ): Response|JsonResponse {
        $this->ensureAssignmentBelongsToCampus(
            $request,
            $teachingAssignment
        );

        if ($teachingAssignment->assessments()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar una asignación docente con evaluaciones. Cámbiala a completed o cancelled.',
            ], 409);
        }

        if (
            $teachingAssignment->attendanceSessions()
                ->exists()
        ) {
            return response()->json([
                'message' =>
                    'No se puede eliminar una asignación docente con sesiones de asistencia. Cámbiala a completed o cancelled.',
            ], 409);
        }

        $teachingAssignment->delete();

        return response()->noContent();
    }

    private function campusId(Request $request): string
    {
        $campusId = $request->attributes->get(
            'campus_id'
        );

        abort_if(
            ! is_string($campusId)
                || $campusId === '',
            400,
            'No se proporcionó un contexto de plantel válido.'
        );

        return $campusId;
    }

    private function ensureAssignmentBelongsToCampus(
        Request $request,
        TeachingAssignment $teachingAssignment
    ): void {
        $campusId = $this->campusId($request);

        $teachingAssignment->loadMissing([
            'schoolGroup.schoolCycle:id,campus_id',
            'subject:id,campus_id',
            'teacher:id,campus_id',
        ]);

        $groupCampusId = $teachingAssignment
            ->schoolGroup
            ?->schoolCycle
            ?->campus_id;

        $subjectCampusId = $teachingAssignment
            ->subject
            ?->campus_id;

        $teacherCampusId = $teachingAssignment
            ->teacher
            ?->campus_id;

        abort_unless(
            $groupCampusId === $campusId
                && $subjectCampusId === $campusId
                && $teacherCampusId === $campusId,
            404,
            'Asignación docente no encontrada.'
        );
    }

    private function loadRelations(
        TeachingAssignment $teachingAssignment
    ): void {
        $teachingAssignment->load([
            'schoolGroup.schoolCycle:id,campus_id,name',
            'subject:id,campus_id,code,name,weekly_hours',
            'teacher.person',
        ]);

        $teachingAssignment->loadCount([
            'assessments',
            'attendanceSessions',
        ]);
    }
}