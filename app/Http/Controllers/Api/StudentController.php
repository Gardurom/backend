<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Person;
use App\Models\Student;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorization,
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
                'in:applicant,active,inactive,graduated,withdrawn,suspended',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $campusId = (string) $request->attributes->get(
            'campus_id'
        );

        $this->setSensitivePermission(
            request: $request,
            campusId: $campusId,
        );

        $students = Student::query()
            ->with([
                'person',
                'campus:id,code,name',
            ])
            ->where('campus_id', $campusId)
            ->when(
                $filters['status'] ?? null,
                fn ($query, string $status) => $query->where(
                    'status',
                    $status
                )
            )
            ->when(
                $filters['search'] ?? null,
                function ($query, string $search): void {
                    $query->where(
                        function ($studentQuery) use ($search): void {
                            $studentQuery
                                ->where(
                                    'enrollment_number',
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
            ->orderBy('enrollment_number')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return StudentResource::collection($students);
    }

    public function store(
        StoreStudentRequest $request
    ): JsonResponse {
        $validated = $request->validated();
        $personData = Arr::pull($validated, 'person');

        $student = DB::transaction(
            function () use (
                $validated,
                $personData
            ): Student {
                $person = Person::create($personData);

                return Student::create([
                    'person_id' => $person->id,
                    'campus_id' => $validated['campus_id'],
                    'enrollment_number' =>
                        $validated['enrollment_number'],
                    'enrolled_on' => $validated['enrolled_on'],
                    'status' => $validated['status'] ?? 'active',
                    'notes' => $validated['notes'] ?? null,
                ]);
            },
            attempts: 3
        );

        $this->setSensitivePermission(
            request: $request,
            campusId: $student->campus_id,
        );

        $student->load([
            'person',
            'campus:id,code,name',
        ]);

        return (new StudentResource($student))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Student $student,
    ): StudentResource {
        $this->setSensitivePermission(
            request: $request,
            campusId: $student->campus_id,
        );

        $student->load([
            'person',
            'campus:id,code,name',
        ]);

        return new StudentResource($student);
    }

    public function update(
        UpdateStudentRequest $request,
        Student $student,
    ): StudentResource {
        $validated = $request->validated();
        $personData = Arr::pull(
            $validated,
            'person',
            []
        );

        DB::transaction(
            function () use (
                $student,
                $validated,
                $personData
            ): void {
                if ($personData !== []) {
                    $student->person->update($personData);
                }

                if ($validated !== []) {
                    $student->update($validated);
                }
            },
            attempts: 3
        );

        $this->setSensitivePermission(
            request: $request,
            campusId: $student->campus_id,
        );

        $student->load([
            'person',
            'campus:id,code,name',
        ]);

        return new StudentResource($student->refresh());
    }

    public function destroy(
        Student $student
    ): Response|JsonResponse {
        if ($student->enrollments()->exists()) {
            return response()->json([
                'message' =>
                    'No se puede eliminar un alumno con inscripciones. '
                    .'Cambia su estado a inactive o withdrawn.',
            ], 409);
        }

        DB::transaction(
            function () use ($student): void {
                $person = $student->person;

                $student->delete();

                if (
                    $person
                    && ! $person->teacher()->exists()
                ) {
                    $person->delete();
                }
            },
            attempts: 3
        );

        return response()->noContent();
    }

    private function setSensitivePermission(
        Request $request,
        string $campusId,
    ): void {
        $canViewSensitive = $this->authorization
            ->userHasPermission(
                user: $request->user(),
                permission: 'students.update',
                campusId: $campusId,
            );

        $request->attributes->set(
            'can_view_sensitive_students',
            $canViewSensitive
        );
    }
}