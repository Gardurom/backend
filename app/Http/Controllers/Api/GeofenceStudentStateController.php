<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeofenceStudentState\IndexGeofenceStudentStateRequest;
use App\Http\Resources\GeofenceStudentStateResource;
use App\Models\Geofence;
use App\Models\GeofenceStudentState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GeofenceStudentStateController extends Controller
{
    public function index(
        IndexGeofenceStudentStateRequest $request,
        Geofence $geofence,
    ): JsonResponse {
        $campusId = $this->campusId($request);

        $this->ensureGeofenceBelongsToCampus(
            $geofence,
            $campusId,
        );

        $validated = $request->validated();
        $limit = (int) ($validated['limit'] ?? 100);

        $query = GeofenceStudentState::query()
            ->where('geofence_id', $geofence->id);

        if (isset($validated['student_id'])) {
            $query->where(
                'student_id',
                $validated['student_id'],
            );
        }

        if (array_key_exists('is_inside', $validated)) {
            $query->where(
                'is_inside',
                $request->boolean('is_inside'),
            );
        }

        $states = $query
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => GeofenceStudentStateResource::collection(
                $states
            ),
            'meta' => [
                'limit' => $limit,
                'count' => $states->count(),
            ],
        ]);
    }

    private function campusId(Request $request): string
    {
        $campusId = $request->attributes->get('campus_id');

        if (! is_string($campusId) || $campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => [
                    'No fue posible determinar el contexto del plantel.',
                ],
            ]);
        }

        return $campusId;
    }

    private function ensureGeofenceBelongsToCampus(
        Geofence $geofence,
        string $campusId,
    ): void {
        if ($geofence->campus_id !== $campusId) {
            abort(
                404,
                'La geocerca indicada no pertenece al plantel actual.'
            );
        }
    }
}