<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentPosition\IndexStudentPositionRequest;
use App\Http\Requests\StudentPosition\StoreStudentPositionRequest;
use App\Http\Resources\GeofenceEventResource;
use App\Http\Resources\StudentPositionResource;
use App\Models\Student;
use App\Services\StudentLocationProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentPositionController extends Controller
{
    public function __construct(
        private readonly StudentLocationProcessor $locationProcessor
    ) {
    }

    public function index(
        IndexStudentPositionRequest $request,
        Student $student
    ): JsonResponse {
        $campusId = (string) $request->attributes->get('campus_id');

        if ($campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => 'No se proporcionó un contexto de plantel válido.',
            ]);
        }

        if ($student->campus_id !== $campusId) {
            abort(404);
        }

        $validated = $request->validated();

        $limit = (int) ($validated['limit'] ?? 100);

        $query = DB::table('student_positions')
            ->where('student_id', $student->id)
            ->select([
                'id',
                'student_id',
                'captured_at',
                'received_at',
                'accuracy_meters',
                'altitude_meters',
                'speed_meters_second',
                'heading_degrees',
                'source',
                'device_reference',
            ])
            ->selectRaw('ST_X(location::geometry) AS longitude')
            ->selectRaw('ST_Y(location::geometry) AS latitude');

        if (isset($validated['from'])) {
            $query->where(
                'captured_at',
                '>=',
                CarbonImmutable::parse($validated['from'])
            );
        }

        if (isset($validated['to'])) {
            $query->where(
                'captured_at',
                '<=',
                CarbonImmutable::parse($validated['to'])
            );
        }

        if (isset($validated['source'])) {
            $query->where('source', $validated['source']);
        }

        $positions = $query
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $resource = StudentPositionResource::collection($positions);

        return response()->json([
            'data' => $resource->resolve($request),
            'meta' => [
                'limit' => $limit,
                'count' => $positions->count(),
            ],
        ]);
    }

    public function store(
        StoreStudentPositionRequest $request,
        Student $student
    ): JsonResponse {
        $campusId = (string) $request->attributes->get('campus_id');

        if ($campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => 'No se proporcionó un contexto de plantel válido.',
            ]);
        }

        if ($student->campus_id !== $campusId) {
            abort(404);
        }

        $validated = $request->validated();

        $result = $this->locationProcessor->record(
            student: $student,
            longitude: (float) $validated['longitude'],
            latitude: (float) $validated['latitude'],
            capturedAt: CarbonImmutable::parse($validated['captured_at']),
            positionId: $validated['position_id'] ?? null,
            accuracyMeters: array_key_exists('accuracy_meters', $validated)
                ? (float) $validated['accuracy_meters']
                : null,
            altitudeMeters: array_key_exists('altitude_meters', $validated)
                ? (float) $validated['altitude_meters']
                : null,
            speedMetersSecond: array_key_exists('speed_meters_second', $validated)
                ? (float) $validated['speed_meters_second']
                : null,
            headingDegrees: array_key_exists('heading_degrees', $validated)
                ? (float) $validated['heading_degrees']
                : null,
            source: $validated['source'] ?? 'gps',
            deviceReference: $validated['device_reference'] ?? null,
            metadata: $validated['metadata'] ?? [],
        );

        $position = new StudentPositionResource(
            $result['position']
        );

        $events = GeofenceEventResource::collection(
            collect($result['events'])
        );

        return response()->json([
            'data' => [
                'position' => $position->resolve($request),
                'events' => $events->resolve($request),
                'was_duplicate' => (bool) $result['was_duplicate'],
            ],
        ], $result['was_duplicate'] ? 200 : 201);
    }
}