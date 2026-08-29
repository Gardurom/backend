<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeofenceStudentAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'geofence_id' => $this->geofence_id,
            'student_id' => $this->student_id,
            'authorized_by_user_id' => $this->authorized_by_user_id,
            'consent_reference' => $this->consent_reference,
            'authorized_at' => $this->authorized_at,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'notification_settings' => $this->notification_settings,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}