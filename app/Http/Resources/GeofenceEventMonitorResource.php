<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeofenceEventMonitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'geofence_id' => $this->geofence_id,
            'student_id' => $this->student_id,
            'assignment_id' => $this->assignment_id,
            'position_id' => $this->position_id,
            'event_type' => $this->event_type,
            'position_captured_at' => $this->position_captured_at,
            'occurred_at' => $this->occurred_at,
            'detected_at' => $this->detected_at,
            'location' => [
                'longitude' => $this->longitude !== null
                    ? (float) $this->longitude
                    : null,
                'latitude' => $this->latitude !== null
                    ? (float) $this->latitude
                    : null,
            ],
        ];
    }
}