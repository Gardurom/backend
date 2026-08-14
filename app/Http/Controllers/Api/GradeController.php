<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Grade\StoreGradeRequest;
use App\Http\Requests\Grade\UpdateGradeRequest;
use App\Http\Resources\GradeResource;
use App\Models\Grade;
use App\Models\TeachingAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GradeController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'assessment_id' => [
                'nullable',
                'uuid',
            ],
            'enrollment_id' => [
                'nullable',
                'uuid',
            ],
            'student_id' => [
                'nullable',
                'uuid',
            ],
            'teaching_assignment_id' => [
                'nullable',
                'uuid',
            ],
            'grading_period_id' => [
                'nullable',
                'uuid',
            ],
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'pending',
                    'graded',
                    'exempt',
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

        $grades = Grade::query()
            ->with([
                'assessment',
                'assessment.gradingPeriod',
                'assessment.teachingAssignment.subject',
                'assessment.teachingAssignment.schoolGroup.schoolCycle',
                'enrollment.student.person',
                'enrollment.schoolGroup.schoolCycle',
                'gradedByTeacher.person',
            ])
            ->whereHas(
                'assessment.teachingAssignment.schoolGroup.schoolCycle',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->whereHas(
                'assessment.teachingAssignment.subject',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->whereHas(
                'enrollment.schoolGroup.schoolCycle',
                fn ($query) => $query->where(
                    'campus_id',
                    $campusId
                )
            )
            ->when(
                $filters['assessment_id'] ?? null,
                fn ($query, string $assessmentId) =>
                    $query->where(
                        'assessment_id',
                        $assessmentId
                    )
            )
            ->when(
                $filters['enrollment_id'] ?? null,
                fn ($query, string $enrollmentId) =>
                    $query->where(
                        'enrollment_id',
                        $enrollmentId
                    )
            )
            ->when(
                $filters['student_id'] ?? null,
                fn ($query, string $studentId) =>
                    $query->whereHas(
                        'enrollment',
                        fn ($enrollmentQuery) =>
                            $enrollmentQuery->where(
                                'student_id',
                                $studentId
                            )
                    )
            )
            ->when(
                $filters['teaching_assignment_id']
                    ?? null,
                fn ($query, string $assignmentId) =>
                    $query->whereHas(
                        'assessment',
                        fn ($assessmentQuery) =>
                            $assessmentQuery->where(
                                'teaching_assignment_id',
                                $assignmentId
                            )
                    )
            )
            ->when(
                $filters['grading_period_id'] ?? null,
                fn ($query, string $periodId) =>
                    $query->whereHas(
                        'assessment',
                        fn ($assessmentQuery) =>
                            $assessmentQuery->where(
                                'grading_period_id',
                                $periodId
                            )
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
                    $searchPattern = "%{$search}%";

                    $query->where(
                        function ($searchQuery) use (
                            $searchPattern
                        ): void {
                            $searchQuery
                                ->where(
                                    'feedback',
                                    'ilike',
                                    $searchPattern
                                )
                                ->orWhereHas(
                                    'assessment',
                                    fn ($assessmentQuery) =>
                                        $assessmentQuery
                                            ->where(
                                                'name',
                                                'ilike',
                                                $searchPattern
                                            )
                                )
                                ->orWhereHas(
                                    'enrollment.student',
                                    fn ($studentQuery) =>
                                        $studentQuery
                                            ->where(
                                                'enrollment_number',
                                                'ilike',
                                                $searchPattern
                                            )
                                )
                                ->orWhereHas(
                                    'enrollment.student.person',
                                    function (
                                        $personQuery
                                    ) use (
                                        $searchPattern
                                    ): void {
                                        /*
                                         * concat_ws omite valores NULL,
                                         * permitiendo buscar el nombre
                                         * completo con una sola cadena.
                                         */
                                        $personQuery->whereRaw(
                                            <<<'SQL'
                                                concat_ws(
                                                    ' ',
                                                    first_name,
                                                    middle_name,
                                                    paternal_surname,
                                                    maternal_surname
                                                ) ILIKE ?
                                            SQL,
                                            [$searchPattern]
                                        );
                                    }
                                );
                        }
                    );
                }
            )
            ->orderByDesc('graded_at')
            ->orderByDesc('created_at')
            ->paginate(
                $filters['per_page'] ?? 20
            )
            ->withQueryString();

        return GradeResource::collection($grades);
    }

    public function store(
        StoreGradeRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $assignment = $this->assignmentForAssessment(
            $validated['assessment_id']
        );

        $status = $validated['status'];

        $validated['graded_by_teacher_id'] =
            $assignment->teacher_id;

        $validated['graded_at'] =
            $status === 'graded'
                ? ($validated['graded_at'] ?? now())
                : null;

        try {
            $grade = Grade::create($validated);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'enrollment_id' => [
                    'La inscripción ya tiene una calificación para esta evaluación.',
                ],
            ]);
        }

        $this->loadRelations($grade);

        return (new GradeResource($grade))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Grade $grade
    ): GradeResource {
        $this->ensureGradeBelongsToCampus(
            $request,
            $grade
        );

        $this->loadRelations($grade);

        return new GradeResource($grade);
    }

    public function update(
        UpdateGradeRequest $request,
        Grade $grade
    ): GradeResource {
        $this->ensureGradeBelongsToCampus(
            $request,
            $grade
        );

        $validated = $request->validated();

        $grade->loadMissing(
            'assessment.teachingAssignment'
        );

        $status = $validated['status']
            ?? $grade->status;

        $validated['graded_by_teacher_id'] =
            $grade
                ->assessment
                ->teachingAssignment
                ->teacher_id;

        if ($status === 'graded') {
            $validated['graded_at'] =
                $validated['graded_at']
                    ?? $grade->graded_at
                    ?? now();
        } else {
            $validated['graded_at'] = null;
        }

        $grade->update($validated);
        $grade->refresh();

        $this->loadRelations($grade);

        return new GradeResource($grade);
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

    private function assignmentForAssessment(
        string $assessmentId
    ): TeachingAssignment {
        $assignment = TeachingAssignment::query()
            ->whereHas(
                'assessments',
                fn ($query) => $query->whereKey(
                    $assessmentId
                )
            )
            ->first();

        if (! $assignment) {
            throw ValidationException::withMessages([
                'assessment_id' => [
                    'No se encontró la asignación docente de la evaluación.',
                ],
            ]);
        }

        return $assignment;
    }

    private function ensureGradeBelongsToCampus(
        Request $request,
        Grade $grade
    ): void {
        $campusId = $this->campusId($request);

        $grade->loadMissing([
            'assessment.gradingPeriod',
            'assessment.teachingAssignment.subject',
            'assessment.teachingAssignment.teacher',
            'assessment.teachingAssignment.schoolGroup.schoolCycle',
            'enrollment.schoolGroup.schoolCycle',
        ]);

        $assessment = $grade->assessment;

        $assignment = $assessment
            ?->teachingAssignment;

        $assessmentCycle = $assignment
            ?->schoolGroup
            ?->schoolCycle;

        $enrollmentCycle = $grade
            ->enrollment
            ?->schoolGroup
            ?->schoolCycle;

        abort_unless(
            $assessmentCycle?->campus_id
                === $campusId
            && $enrollmentCycle?->campus_id
                === $campusId
            && $assignment?->subject?->campus_id
                === $campusId
            && $assignment?->teacher?->campus_id
                === $campusId
            && $grade->enrollment?->school_group_id
                === $assignment?->school_group_id
            && $grade->enrollment?->school_cycle_id
                === $assessmentCycle?->id
            && $assessment
                ?->gradingPeriod
                ?->school_cycle_id
                === $assessmentCycle?->id,
            404,
            'Calificación no encontrada.'
        );
    }

    private function loadRelations(
        Grade $grade
    ): void {
        $grade->load([
            'assessment',
            'assessment.gradingPeriod',
            'assessment.teachingAssignment.subject',
            'assessment.teachingAssignment.schoolGroup.schoolCycle',
            'enrollment.student.person',
            'enrollment.schoolGroup.schoolCycle',
            'gradedByTeacher.person',
        ]);
    }
}