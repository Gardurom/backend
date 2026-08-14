<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\StoreAssessmentRequest;
use App\Http\Requests\Assessment\UpdateAssessmentRequest;
use App\Http\Resources\AssessmentResource;
use App\Models\Assessment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssessmentController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'teaching_assignment_id' => [
                'nullable',
                'uuid',
            ],

            'grading_period_id' => [
                'nullable',
                'uuid',
            ],

            'type' => [
                'nullable',
                'string',
                Rule::in([
                    'exam',
                    'quiz',
                    'homework',
                    'project',
                    'participation',
                    'practice',
                    'other',
                ]),
            ],

            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'draft',
                    'published',
                    'closed',
                    'cancelled',
                ]),
            ],

            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'due_from' => [
                'nullable',
                'date',
            ],

            'due_to' => [
                'nullable',
                'date',
                'after_or_equal:due_from',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'between:1,100',
            ],
        ]);

        $campusId = $this->campusId($request);

        $assessments = Assessment::query()
            ->with([
                'gradingPeriod',
                'teachingAssignment.subject',
                'teachingAssignment.teacher.person',
                'teachingAssignment.schoolGroup.schoolCycle',
            ])
            ->withCount('grades')
            ->whereHas(
                'teachingAssignment.schoolGroup.schoolCycle',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->whereHas(
                'teachingAssignment.subject',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->whereHas(
                'teachingAssignment.teacher',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->when(
                $filters['teaching_assignment_id']
                    ?? null,
                fn (
                    $query,
                    string $assignmentId
                ) => $query->where(
                    'teaching_assignment_id',
                    $assignmentId
                )
            )
            ->when(
                $filters['grading_period_id']
                    ?? null,
                fn (
                    $query,
                    string $gradingPeriodId
                ) => $query->where(
                    'grading_period_id',
                    $gradingPeriodId
                )
            )
            ->when(
                $filters['type'] ?? null,
                fn (
                    $query,
                    string $type
                ) => $query->where(
                    'type',
                    $type
                )
            )
            ->when(
                $filters['status'] ?? null,
                fn (
                    $query,
                    string $status
                ) => $query->where(
                    'status',
                    $status
                )
            )
            ->when(
                $filters['due_from'] ?? null,
                fn (
                    $query,
                    string $dueFrom
                ) => $query->where(
                    'due_at',
                    '>=',
                    $dueFrom
                )
            )
            ->when(
                $filters['due_to'] ?? null,
                fn (
                    $query,
                    string $dueTo
                ) => $query->where(
                    'due_at',
                    '<=',
                    $dueTo.' 23:59:59'
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
                                ->where(
                                    'name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'description',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'teachingAssignment.subject',
                                    function (
                                        $subjectQuery
                                    ) use ($search): void {
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
                                    'teachingAssignment.teacher',
                                    fn ($teacherQuery) =>
                                        $teacherQuery->where(
                                            'employee_number',
                                            'ilike',
                                            "%{$search}%"
                                        )
                                )
                                ->orWhereHas(
                                    'teachingAssignment.teacher.person',
                                    function (
                                        $personQuery
                                    ) use ($search): void {
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
                                );
                        }
                    );
                }
            )
            ->orderByRaw(
                'due_at IS NULL ASC'
            )
            ->orderBy('due_at')
            ->orderByDesc('created_at')
            ->paginate(
                $filters['per_page'] ?? 20
            )
            ->withQueryString();

        return AssessmentResource::collection(
            $assessments
        );
    }

    public function store(
        StoreAssessmentRequest $request
    ): JsonResponse {
        try {
            $assessment = Assessment::create(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => [
                    'Ya existe una evaluación con este nombre en la asignación y periodo seleccionados.',
                ],
            ]);
        }

        $this->loadRelations($assessment);

        return (new AssessmentResource($assessment))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Assessment $assessment
    ): AssessmentResource {
        $this->ensureAssessmentBelongsToCampus(
            $request,
            $assessment
        );

        $this->loadRelations($assessment);

        return new AssessmentResource($assessment);
    }

    public function update(
        UpdateAssessmentRequest $request,
        Assessment $assessment
    ): AssessmentResource {
        $this->ensureAssessmentBelongsToCampus(
            $request,
            $assessment
        );

        try {
            $assessment->update(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => [
                    'Ya existe una evaluación con este nombre en la asignación y periodo seleccionados.',
                ],
            ]);
        }

        $assessment->refresh();

        $this->loadRelations($assessment);

        return new AssessmentResource($assessment);
    }

    public function destroy(
        Request $request,
        Assessment $assessment
    ): Response|JsonResponse {
        $this->ensureAssessmentBelongsToCampus(
            $request,
            $assessment
        );

        if ($assessment->grades()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar una evaluación que tiene calificaciones. Cámbiala a cancelled.',
            ], 409);
        }

        $assessment->delete();

        return response()->noContent();
    }

    private function campusId(
        Request $request
    ): string {
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

    private function ensureAssessmentBelongsToCampus(
        Request $request,
        Assessment $assessment
    ): void {
        $campusId = $this->campusId($request);

        $assessment->loadMissing([
            'teachingAssignment.schoolGroup.schoolCycle',
            'teachingAssignment.subject',
            'teachingAssignment.teacher',
            'gradingPeriod',
        ]);

        $assignment = $assessment
            ->teachingAssignment;

        $schoolCycle = $assignment
            ?->schoolGroup
            ?->schoolCycle;

        $groupCampusId = $schoolCycle
            ?->campus_id;

        $subjectCampusId = $assignment
            ?->subject
            ?->campus_id;

        $teacherCampusId = $assignment
            ?->teacher
            ?->campus_id;

        $periodSchoolCycleId = $assessment
            ->gradingPeriod
            ?->school_cycle_id;

        abort_unless(
            $groupCampusId === $campusId
                && $subjectCampusId === $campusId
                && $teacherCampusId === $campusId
                && $periodSchoolCycleId
                    === $schoolCycle?->id,
            404,
            'Evaluación no encontrada.'
        );
    }

    private function loadRelations(
        Assessment $assessment
    ): void {
        $assessment->load([
            'gradingPeriod',
            'teachingAssignment.subject',
            'teachingAssignment.teacher.person',
            'teachingAssignment.schoolGroup.schoolCycle',
        ]);

        $assessment->loadCount('grades');
    }
}