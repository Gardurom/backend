<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeofenceEvent\IndexGeofenceEventRequest;
use App\Http\Resources\GeofenceEventMonitorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GeofenceEventController extends Controller
{
    public function index(IndexGeofenceEventRequest $request): JsonResponse
    {
        $campusId = $request->attributes->get('campus_id');

        if (! is_string($campusId) || $campusId === '') {
            throw ValidationException::withMessages([
                'campus_id' => [
                    'No fue posible determinar el contexto del plantel.',
                ],
            ]);
        }

        $validated = $request->validated();
        $limit = (int) ($validated['limit'] ?? 100);

        $query = DB::table('geofence_events')
            ->join(
                'geofences',
                'geofences.id',
                '=',
                'geofence_events.geofence_id'
            )
            ->where('geofences.campus_id', $campusId)
            ->select([
                'geofence_events.id',
                'geofence_events.geofence_id',
                'geofence_events.student_id',
                'geofence_events.assignment_id',
                'geofence_events.position_id',
                'geofence_events.position_captured_at',
                'geofence_events.event_type',
                'geofence_events.occurred_at',
                'geofence_events.detected_at',
            ])
            ->selectRaw(
                'ST_X(geofence_events.location::geometry) AS longitude'
            )
            ->selectRaw(
                'ST_Y(geofence_events.location::geometry) AS latitude'
            );

        if (isset($validated['geofence_id'])) {
            $query->where(
                'geofence_events.geofence_id',
                $validated['geofence_id']
            );
        }

        if (isset($validated['student_id'])) {
            $query->where(
                'geofence_events.student_id',
                $validated['student_id']
            );
        }

        if (isset($validated['event_type'])) {
            $query->where(
                'geofence_events.event_type',
                $validated['event_type']
            );
        }

        if (isset($validated['from'])) {
            $query->where(
                'geofence_events.occurred_at',
                '>=',
                $validated['from']
            );
        }

        if (isset($validated['to'])) {
            $query->where(
                'geofence_events.occurred_at',
                '<=',
                $validated['to']
            );
        }

        $events = $query
            ->orderByDesc('geofence_events.occurred_at')
            ->orderByDesc('geofence_events.id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => GeofenceEventMonitorResource::collection($events),
            'meta' => [
                'limit' => $limit,
                'count' => $events->count(),
            ],
        ]);
    }
}