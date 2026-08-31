<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonException;

class StudentAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'locality_id' => $this->locality_id,
            'street' => $this->street,
            'external_number' => $this->external_number,
            'internal_number' => $this->internal_number,
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->postal_code,
            'address_reference' => $this->address_reference,
            'accuracy_meters' => $this->accuracy_meters,
            'location_source' => $this->location_source,
            'is_primary' => (bool) $this->is_primary,
            'is_verified' => (bool) $this->is_verified,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'verified_at' => $this->verified_at,
            'location' => $this->decodeGeoJson(
                $this->location_geojson ?? null
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function decodeGeoJson(
        ?string $geoJson,
    ): ?array {
        if ($geoJson === null) {
            return null;
        }

        try {
            return json_decode(
                $geoJson,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return null;
        }
    }
}