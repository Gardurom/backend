<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subject\StoreSubjectRequest;
use App\Http\Requests\Subject\UpdateSubjectRequest;
use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $filters = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'between:1,100',
            ],
        ]);

        $campusId = $this->campusId($request);

        $subjects = Subject::query()
            ->with([
                'campus:id,code,name',
            ])
            ->withCount('teachingAssignments')
            ->where('campus_id', $campusId)
            ->when(
                array_key_exists('is_active', $filters),
                fn ($query) => $query->where(
                    'is_active',
                    $filters['is_active']
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
                                    'code',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'name',
                                    'ilike',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'description',
                                    'ilike',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )
            ->orderBy('name')
            ->orderBy('code')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return SubjectResource::collection($subjects);
    }

    public function store(
        StoreSubjectRequest $request
    ): JsonResponse {
        try {
            $subject = Subject::create(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => [
                    'El código de la materia ya existe en este plantel.',
                ],
            ]);
        }

        $subject->load([
            'campus:id,code,name',
        ]);

        $subject->loadCount('teachingAssignments');

        return (new SubjectResource($subject))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        Request $request,
        Subject $subject
    ): SubjectResource {
        $this->ensureSubjectBelongsToCampus(
            $request,
            $subject
        );

        $subject->load([
            'campus:id,code,name',
        ]);

        $subject->loadCount('teachingAssignments');

        return new SubjectResource($subject);
    }

    public function update(
        UpdateSubjectRequest $request,
        Subject $subject
    ): SubjectResource {
        $this->ensureSubjectBelongsToCampus(
            $request,
            $subject
        );

        try {
            $subject->update(
                $request->validated()
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => [
                    'El código de la materia ya existe en este plantel.',
                ],
            ]);
        }

        $subject->refresh();

        $subject->load([
            'campus:id,code,name',
        ]);

        $subject->loadCount('teachingAssignments');

        return new SubjectResource($subject);
    }

    public function destroy(
        Request $request,
        Subject $subject
    ): Response|JsonResponse {
        $this->ensureSubjectBelongsToCampus(
            $request,
            $subject
        );

        if (
            $subject->teachingAssignments()
                ->exists()
        ) {
            return response()->json([
                'message' =>
                    'No se puede eliminar una materia con asignaciones docentes. Desactívala en su lugar.',
            ], 409);
        }

        $subject->delete();

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

    private function ensureSubjectBelongsToCampus(
        Request $request,
        Subject $subject
    ): void {
        abort_unless(
            $subject->campus_id
                === $this->campusId($request),
            404,
            'Materia no encontrada.'
        );
    }
}