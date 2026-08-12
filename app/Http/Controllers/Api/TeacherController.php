<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teacher\StoreTeacherRequest;
use App\Http\Requests\Teacher\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Models\Person;
use App\Models\Teacher;
use App\Services\AuthorizationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeacherController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorization
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
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'active',
                    'inactive',
                    'leave',
                    'terminated',
                ]),
            ],
            'per_page' => [
                'nullable',
                'integer',
                'between:1,100',
            ],
        ]);

        $campusId = $this->campusId($request);

        $this->setSensitivePermission(
            $request,
            $campusId
        );

        $teachers = Teacher::query()
            ->with([
                'person',
                'campus:id,code,name',
            ])
            ->withCount('teachingAssignments')
            ->where('campus_id', $campusId)
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) =>
                    $query->where('status', $status)
            )
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search): void {
                    $query->where(
                        function ($teacherQuery) use (
                            $search
                        ): void {
                            $teacherQuery
                                ->where(
                                    'employee_number',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'professional_license',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhereHas(
                                    'person',
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
                                            )
                                            ->orWhere(
                                                'curp',
                                                'ilike',
                                                "%{$search}%"
                                            );
                                    }
                                );
                        }
                    );
                }
            )
            ->orderBy('employee_number')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return TeacherResource::collection($teachers);
    }

    public function store(
        StoreTeacherRequest $request
    ): JsonResponse {
        $validated = $request->validated();
        $personData = Arr::pull($validated, 'person');

        try {
            $teacher = DB::transaction(
                function () use (
                    $validated,
                    $personData
                ): Teacher {
                    $person = Person::create($personData);

                    return Teacher::create([
                        'person_id' => $person->id,
                        'campus_id' =>
                            $validated['campus_id'],
                        'employee_number' =>
                            $validated['employee_number'],
                        'professional_license' =>
                            $validated[
                                'professional_license'
                            ] ?? null,
                        'hired_on' =>
                            $validated['hired_on'] ?? null,
                        'terminated_on' =>
                            $validated[
                                'terminated_on'
                            ] ?? null,
                        'status' =>
                            $validated['status'] ?? 'active',
                    ]);
                },
                attempts: 3
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'teacher' => [
                    'El número de empleado o la CURP ya están registrados.',
                ],
            ]);
        }

        $this->setSensitivePermission(
            $request,
            $teacher->campus_id
        );

        $teacher->load([
            'person',
            'campus:id,code,name',
        ]);

        $teacher->loadCount('teachingAssignments');

        return (new TeacherResource($teacher))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Teacher $teacher
    ): TeacherResource {
        $this->ensureTeacherBelongsToCampus(
            $request,
            $teacher
        );

        $this->setSensitivePermission(
            $request,
            $teacher->campus_id
        );

        $teacher->load([
            'person',
            'campus:id,code,name',
        ]);

        $teacher->loadCount('teachingAssignments');

        return new TeacherResource($teacher);
    }

    public function update(
        UpdateTeacherRequest $request,
        Teacher $teacher
    ): TeacherResource {
        $this->ensureTeacherBelongsToCampus(
            $request,
            $teacher
        );

        $validated = $request->validated();
        $personData = Arr::pull(
            $validated,
            'person',
            []
        );

        try {
            DB::transaction(
                function () use (
                    $teacher,
                    $validated,
                    $personData
                ): void {
                    if ($personData !== []) {
                        $teacher->person->update(
                            $personData
                        );
                    }

                    if ($validated !== []) {
                        $teacher->update($validated);
                    }
                },
                attempts: 3
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'teacher' => [
                    'El número de empleado o la CURP ya están registrados.',
                ],
            ]);
        }

        $teacher->refresh();

        $this->setSensitivePermission(
            $request,
            $teacher->campus_id
        );

        $teacher->load([
            'person',
            'campus:id,code,name',
        ]);

        $teacher->loadCount('teachingAssignments');

        return new TeacherResource($teacher);
    }

    public function destroy(
        Request $request,
        Teacher $teacher
    ): Response|JsonResponse {
        $this->ensureTeacherBelongsToCampus(
            $request,
            $teacher
        );

        if ($teacher->teachingAssignments()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un profesor con asignaciones docentes. Desactívalo en su lugar.',
            ], 409);
        }

        if ($teacher->gradedGrades()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un profesor asociado con calificaciones.',
            ], 409);
        }

        if (
            $teacher->recordedAttendanceSessions()
                ->exists()
        ) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un profesor asociado con asistencias.',
            ], 409);
        }

        DB::transaction(
            function () use ($teacher): void {
                $person = $teacher->person;

                $teacher->delete();

                if (
                    $person
                    && ! $person->student()->exists()
                ) {
                    $person->delete();
                }
            },
            attempts: 3
        );

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

    private function ensureTeacherBelongsToCampus(
        Request $request,
        Teacher $teacher
    ): void {
        abort_unless(
            $teacher->campus_id
                === $this->campusId($request),
            404,
            'Profesor no encontrado.'
        );
    }

    private function setSensitivePermission(
        Request $request,
        string $campusId
    ): void {
        $canViewSensitive = $this->authorization
            ->userHasPermission(
                user: $request->user(),
                permission: 'teachers.update',
                campusId: $campusId
            );

        $request->attributes->set(
            'can_view_sensitive_teachers',
            $canViewSensitive
        );
    }
}