<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentPosition\StoreStudentPositionRequest;
use App\Http\Resources\GeofenceEventResource;
use App\Http\Resources\StudentPositionResource;
use App\Models\Student;
use App\Services\StudentLocationProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class StudentPositionController extends Controller
{
    public function __construct(
        private readonly StudentLocationProcessor $locationProcessor,
    ) {
    }

    public function store(
        StoreStudentPositionRequest $request,
        Student $student,
    ): JsonResponse {
        $campusId = (string) $request->attributes->get(
            'campus_id'
        );

        if ($campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' =>
                    'No se proporcionó un contexto de plantel válido.',
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
            capturedAt: CarbonImmutable::parse(
                $validated['captured_at']
            ),
            positionId:
                $validated['position_id'] ?? null,
            accuracyMeters: array_key_exists(
                'accuracy_meters',
                $validated
            )
                ? (float) $validated['accuracy_meters']
                : null,
            altitudeMeters: array_key_exists(
                'altitude_meters',
                $validated
            )
                ? (float) $validated['altitude_meters']
                : null,
            speedMetersSecond: array_key_exists(
                'speed_meters_second',
                $validated
            )
                ? (float) $validated['speed_meters_second']
                : null,
            headingDegrees: array_key_exists(
                'heading_degrees',
                $validated
            )
                ? (float) $validated['heading_degrees']
                : null,
            source:
                $validated['source'] ?? 'gps',
            deviceReference:
                $validated['device_reference'] ?? null,
            metadata:
                $validated['metadata'] ?? [],
        );

        $position = new StudentPositionResource(
            $result['position']
        );

        $events = GeofenceEventResource::collection(
            collect($result['events'])
        );

        return response()->json(
            [
                'data' => [
                    'position' => $position->resolve(
                        $request
                    ),
                    'events' => $events
                        ->resolve($request),
                    'was_duplicate' =>
                        (bool) $result['was_duplicate'],
                ],
            ],
            $result['was_duplicate']
                ? 200
                : 201
        );
    }
}