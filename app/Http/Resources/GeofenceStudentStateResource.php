<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeofenceStudentStateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'geofence_id' => $this->geofence_id,
            'student_id' => $this->student_id,
            'is_inside' => (bool) $this->is_inside,
            'last_position_id' => $this->last_position_id,
            'last_position_captured_at' =>
                $this->last_position_captured_at,
            'state_changed_at' => $this->state_changed_at,
            'updated_at' => $this->updated_at,
        ];
    }
}