<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentPositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,

            'captured_at' => $this->captured_at,
            'received_at' => $this->received_at,

            'location' => [
                'longitude' => (float) $this->longitude,
                'latitude' => (float) $this->latitude,
            ],

            'accuracy_meters' => $this->accuracy_meters !== null
                ? (float) $this->accuracy_meters
                : null,

            'altitude_meters' => $this->altitude_meters !== null
                ? (float) $this->altitude_meters
                : null,

            'speed_meters_second' => $this->speed_meters_second !== null
                ? (float) $this->speed_meters_second
                : null,

            'heading_degrees' => $this->heading_degrees !== null
                ? (float) $this->heading_degrees
                : null,

            'source' => $this->source,
            'device_reference' => $this->device_reference,
        ];
    }
}