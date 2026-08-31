<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonException;

class LocalityDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = (new LocalityResource(
            $this->resource
        ))->toArray($request);

        return array_merge(
            $base,
            [
                'boundary' => $this->decodeGeoJson(
                    $this->boundary_geojson ?? null
                ),
                'representative_point' => $this->decodeGeoJson(
                    $this->representative_point_geojson ?? null
                ),
            ],
        );
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