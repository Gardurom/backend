<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreAttendanceSessionRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRecordsRequest;
use App\Http\Requests\Attendance\UpdateAttendanceSessionRequest;
use App\Http\Resources\AttendanceSessionResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttendanceSessionController extends Controller
{
    public function index(
        Request $request
    ): AnonymousResourceCollection {
        $campusId = $this->campusId($request);

        $perPage = min(
            max((int) $request->input('per_page', 15), 1),
            100
        );

        $query = AttendanceSession::query()
            ->with([
                'schoolGroup.schoolCycle',
                'teachingAssignment.subject',
                'recordedByTeacher.person',
            ])
            ->withCount([
                'records',
                'records as pending_records_count' => function (
                    Builder $query
                ): void {
                    $query->where('status', 'pending');
                },
                'records as present_records_count' => function (
                    Builder $query
                ): void {
                    $query->where('status', 'present');
                },
                'records as absent_records_count' => function (
                    Builder $query
                ): void {
                    $query->where('status', 'absent');
                },
                'records as late_records_count' => function (
                    Builder $query
                ): void {
                    $query->where('status', 'late');
                },
                'records as excused_records_count' => function (
                    Builder $query
                ): void {
                    $query->where('status', 'excused');
                },
            ])
            ->whereHas(
                'schoolGroup.schoolCycle',
                function (Builder $query) use ($campusId): void {
                    $query->where('campus_id', $campusId);
                }
            );

        $query->when(
            $request->filled('school_group_id'),
            function (Builder $query) use ($request): void {
                $query->where(
                    'school_group_id',
                    $request->string('school_group_id')->toString()
                );
            }
        );

        $query->when(
            $request->filled('teaching_assignment_id'),
            function (Builder $query) use ($request): void {
                $query->where(
                    'teaching_assignment_id',
                    $request
                        ->string('teaching_assignment_id')
                        ->toString()
                );
            }
        );

        $query->when(
            $request->filled('recorded_by_teacher_id'),
            function (Builder $query) use ($request): void {
                $query->where(
                    'recorded_by_teacher_id',
                    $request
                        ->string('recorded_by_teacher_id')
                        ->toString()
                );
            }
        );

        $query->when(
            $request->filled('type'),
            function (Builder $query) use ($request): void {
                $query->where(
                    'type',
                    $request->string('type')->toString()
                );
            }
        );

        $query->when(
            $request->filled('status'),
            function (Builder $query) use ($request): void {
                $query->where(
                    'status',
                    $request->string('status')->toString()
                );
            }
        );

        $query->when(
            $request->filled('date_from'),
            function (Builder $query) use ($request): void {
                $query->whereDate(
                    'held_on',
                    '>=',
                    $request->input('date_from')
                );
            }
        );

        $query->when(
            $request->filled('date_to'),
            function (Builder $query) use ($request): void {
                $query->whereDate(
                    'held_on',
                    '<=',
                    $request->input('date_to')
                );
            }
        );

        $query->when(
            $request->filled('search'),
            function (Builder $query) use ($request): void {
                $search = trim(
                    $request->string('search')->toString()
                );

                $query->where(
                    function (Builder $query) use ($search): void {
                        $like = "%{$search}%";

                        $query
                            ->where('notes', 'ILIKE', $like)
                            ->orWhereHas(
                                'schoolGroup',
                                function (
                                    Builder $query
                                ) use ($like): void {
                                    $query
                                        ->where(
                                            'grade_level',
                                            'ILIKE',
                                            $like
                                        )
                                        ->orWhere(
                                            'section',
                                            'ILIKE',
                                            $like
                                        );
                                }
                            )
                            ->orWhereHas(
                                'teachingAssignment.subject',
                                function (
                                    Builder $query
                                ) use ($like): void {
                                    $query
                                        ->where('name', 'ILIKE', $like)
                                        ->orWhere(
                                            'code',
                                            'ILIKE',
                                            $like
                                        );
                                }
                            )
                            ->orWhereHas(
                                'recordedByTeacher.person',
                                function (
                                    Builder $query
                                ) use ($like): void {
                                    $query->whereRaw(
                                        <<<'SQL'
concat_ws(
    ' ',
    first_name,
    middle_name,
    paternal_surname,
    maternal_surname
) ILIKE ?
SQL,
                                        [$like]
                                    );
                                }
                            );
                    }
                );
            }
        );

        $sessions = $query
            ->orderByDesc('held_on')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return AttendanceSessionResource::collection($sessions);
    }

    public function store(
        StoreAttendanceSessionRequest $request
    ): JsonResponse {
        $campusId = $this->campusId($request);
        $validated = $request->validated();

        try {
            $attendanceSession = DB::transaction(
                function () use (
                    $validated,
                    $campusId
                ): AttendanceSession {
                    $schoolGroup = \App\Models\SchoolGroup::query()
                        ->with('schoolCycle')
                        ->whereKey($validated['school_group_id'])
                        ->whereHas(
                            'schoolCycle',
                            function (
                                Builder $query
                            ) use ($campusId): void {
                                $query->where(
                                    'campus_id',
                                    $campusId
                                );
                            }
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                    $duplicateQuery = AttendanceSession::query()
                        ->where(
                            'school_group_id',
                            $schoolGroup->id
                        )
                        ->whereDate(
                            'held_on',
                            $validated['held_on']
                        )
                        ->where(
                            'type',
                            $validated['type']
                        )
                        ->whereNotIn(
                            'status',
                            ['cancelled']
                        );

                    if (
                        ! empty(
                            $validated[
                                'teaching_assignment_id'
                            ] ?? null
                        )
                    ) {
                        $duplicateQuery->where(
                            'teaching_assignment_id',
                            $validated[
                                'teaching_assignment_id'
                            ]
                        );
                    } else {
                        $duplicateQuery->whereNull(
                            'teaching_assignment_id'
                        );
                    }

                    if (
                        ! empty($validated['starts_at'] ?? null)
                    ) {
                        $duplicateQuery->where(
                            'starts_at',
                            $validated['starts_at']
                        );
                    } else {
                        $duplicateQuery->whereNull('starts_at');
                    }

                    if ($duplicateQuery->exists()) {
                        throw ValidationException::withMessages([
                            'held_on' => [
                                'Ya existe una sesión de asistencia equivalente para este grupo, fecha y horario.',
                            ],
                        ]);
                    }

                    $attendanceSession =
                        AttendanceSession::query()->create([
                            'school_group_id' =>
                                $schoolGroup->id,

                            'teaching_assignment_id' =>
                                $validated[
                                    'teaching_assignment_id'
                                ] ?? null,

                            'recorded_by_teacher_id' =>
                                $validated[
                                    'recorded_by_teacher_id'
                                ] ?? null,

                            'held_on' =>
                                $validated['held_on'],

                            'starts_at' =>
                                $validated['starts_at'] ?? null,

                            'ends_at' =>
                                $validated['ends_at'] ?? null,

                            'type' =>
                                $validated['type'],

                            'status' =>
                                $validated['status']
                                    ?? 'scheduled',

                            'notes' =>
                                $validated['notes'] ?? null,
                        ]);

                    $enrollments = Enrollment::query()
                        ->where(
                            'school_group_id',
                            $schoolGroup->id
                        )
                        ->where(
                            'school_cycle_id',
                            $schoolGroup->school_cycle_id
                        )
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get(['id']);

                    foreach ($enrollments as $enrollment) {
                        AttendanceRecord::query()->create([
                            'attendance_session_id' =>
                                $attendanceSession->id,

                            'enrollment_id' =>
                                $enrollment->id,

                            'status' => 'pending',
                            'minutes_late' => 0,
                            'notes' => null,
                            'recorded_at' => null,
                        ]);
                    }

                    return $attendanceSession;
                },
                3
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'No fue posible crear la sesión de asistencia.',
            ], 500);
        }

        $this->loadSessionRelations($attendanceSession);

        return response()->json([
            'message' =>
                'Sesión de asistencia creada correctamente.',

            'data' => new AttendanceSessionResource(
                $attendanceSession
            ),
        ], 201);
    }

    public function show(
        Request $request,
        AttendanceSession $attendanceSession
    ): AttendanceSessionResource {
        $this->ensureSessionBelongsToCampus(
            $attendanceSession,
            $this->campusId($request)
        );

        $this->loadSessionRelations($attendanceSession);

        return new AttendanceSessionResource(
            $attendanceSession
        );
    }

    public function update(
        UpdateAttendanceSessionRequest $request,
        AttendanceSession $attendanceSession
    ): JsonResponse {
        $this->ensureSessionBelongsToCampus(
            $attendanceSession,
            $this->campusId($request)
        );

        $validated = $request->validated();

        if (
            in_array(
                $attendanceSession->status,
                ['closed', 'cancelled'],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    'Una sesión cerrada o cancelada ya no puede modificarse.',
                ],
            ]);
        }

        $newStatus = $validated['status']
            ?? $attendanceSession->status;

        if (
            $newStatus === 'closed'
            && $attendanceSession
                ->records()
                ->where('status', 'pending')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    'No puede cerrar la sesión mientras existan asistencias pendientes.',
                ],
            ]);
        }

        if (
            $newStatus === 'cancelled'
            && $attendanceSession
                ->records()
                ->where('status', '!=', 'pending')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    'No puede cancelar una sesión que ya contiene asistencias registradas.',
                ],
            ]);
        }

        try {
            DB::transaction(
                function () use (
                    $attendanceSession,
                    $validated
                ): void {
                    $attendanceSession
                        ->lockForUpdate()
                        ->firstOrFail();

                    $attendanceSession->update($validated);
                },
                3
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'No fue posible actualizar la sesión de asistencia.',
            ], 500);
        }

        $attendanceSession->refresh();
        $this->loadSessionRelations($attendanceSession);

        return response()->json([
            'message' =>
                'Sesión de asistencia actualizada correctamente.',

            'data' => new AttendanceSessionResource(
                $attendanceSession
            ),
        ]);
    }

    public function updateRecords(
        UpdateAttendanceRecordsRequest $request,
        AttendanceSession $attendanceSession
    ): JsonResponse {
        $this->ensureSessionBelongsToCampus(
            $attendanceSession,
            $this->campusId($request)
        );

        $validated = $request->validated();

        try {
            DB::transaction(
                function () use (
                    $attendanceSession,
                    $validated
                ): void {
                    $lockedSession = AttendanceSession::query()
                        ->whereKey($attendanceSession->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedSession->status !== 'open') {
                        throw ValidationException::withMessages([
                            'attendance_session' => [
                                'Solo se pueden registrar asistencias en una sesión abierta.',
                            ],
                        ]);
                    }

                    foreach ($validated['records'] as $record) {
                        $attendanceRecord =
                            AttendanceRecord::query()
                                ->where(
                                    'attendance_session_id',
                                    $lockedSession->id
                                )
                                ->where(
                                    'enrollment_id',
                                    $record['enrollment_id']
                                )
                                ->lockForUpdate()
                                ->first();

                        if (! $attendanceRecord) {
                            throw ValidationException::withMessages([
                                'records' => [
                                    'Uno de los alumnos no pertenece a esta sesión de asistencia.',
                                ],
                            ]);
                        }

                        $attendanceRecord->update([
                            'status' => $record['status'],

                            'minutes_late' =>
                                $record['status'] === 'late'
                                    ? (int) $record[
                                        'minutes_late'
                                    ]
                                    : 0,

                            'notes' =>
                                $record['notes'] ?? null,

                            'recorded_at' => now(),
                        ]);
                    }
                },
                3
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' =>
                    'No fue posible guardar los registros de asistencia.',
            ], 500);
        }

        $attendanceSession->refresh();
        $this->loadSessionRelations($attendanceSession);

        return response()->json([
            'message' =>
                'Registros de asistencia actualizados correctamente.',

            'data' => new AttendanceSessionResource(
                $attendanceSession
            ),
        ]);
    }

    private function campusId(Request $request): string
    {
        $campusId = $request->attributes->get('campus_id')
            ?? $request->header('X-Campus-ID');

        if (
            ! is_string($campusId)
            || trim($campusId) === ''
        ) {
            abort(
                400,
                'Debe proporcionar el encabezado X-Campus-ID.'
            );
        }

        return trim($campusId);
    }

    private function ensureSessionBelongsToCampus(
        AttendanceSession $attendanceSession,
        string $campusId
    ): void {
        $belongsToCampus = AttendanceSession::query()
            ->whereKey($attendanceSession->id)
            ->whereHas(
                'schoolGroup.schoolCycle',
                function (
                    Builder $query
                ) use ($campusId): void {
                    $query->where(
                        'campus_id',
                        $campusId
                    );
                }
            )
            ->exists();

        abort_unless($belongsToCampus, 404);
    }

    private function loadSessionRelations(
        AttendanceSession $attendanceSession
    ): void {
        $attendanceSession->load([
            'schoolGroup.schoolCycle',
            'teachingAssignment.subject',
            'teachingAssignment.teacher.person',
            'recordedByTeacher.person',
            'records' => function ($query): void {
    $query
        ->with([
            'enrollment.student.person',
        ])
        ->orderBy('enrollment_id');
},
        ]);

        $attendanceSession->loadCount([
            'records',
            'records as pending_records_count' => function (
                Builder $query
            ): void {
                $query->where('status', 'pending');
            },
            'records as present_records_count' => function (
                Builder $query
            ): void {
                $query->where('status', 'present');
            },
            'records as absent_records_count' => function (
                Builder $query
            ): void {
                $query->where('status', 'absent');
            },
            'records as late_records_count' => function (
                Builder $query
            ): void {
                $query->where('status', 'late');
            },
            'records as excused_records_count' => function (
                Builder $query
            ): void {
                $query->where('status', 'excused');
            },
        ]);
    }
}