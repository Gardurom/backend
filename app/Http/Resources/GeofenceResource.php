<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeofenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $campus = $this->whenLoaded('campus');
        $creator = $this->whenLoaded('creator');

        return [
            'id' => $this->id,
            'campus_id' => $this->campus_id,
            'created_by_user_id' => $this->created_by_user_id,

            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,

            'radius_meters' => $this->radius_meters !== null
                ? (float) $this->radius_meters
                : null,

            'detect_entry' => (bool) $this->detect_entry,
            'detect_exit' => (bool) $this->detect_exit,

            'schedule' => $this->schedule ?? [],

            'valid_from' => $this->valid_from?->toISOString(),
            'valid_until' => $this->valid_until?->toISOString(),

            'is_active' => (bool) $this->is_active,

            'geometry' => $this->geometryGeoJson(),

            'center' => $this->centerCoordinates(),

            'campus' => $campus
                ? [
                    'id' => $campus->id,
                    'code' => $campus->code,
                    'name' => $campus->name,
                ]
                : null,

            'creator' => $creator
                ? [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ]
                : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function geometryGeoJson(): ?array
    {
        $geoJson = $this->getAttribute(
            'geometry_geojson'
        );

        if (! is_string($geoJson) || $geoJson === '') {
            return null;
        }

        $decoded = json_decode(
            $geoJson,
            true
        );

        return is_array($decoded)
            ? $decoded
            : null;
    }

    private function centerCoordinates(): ?array
    {
        $longitude = $this->getAttribute(
            'center_longitude'
        );

        $latitude = $this->getAttribute(
            'center_latitude'
        );

        if ($longitude === null || $latitude === null) {
            return null;
        }

        return [
            'longitude' => (float) $longitude,
            'latitude' => (float) $latitude,
        ];
    }
}