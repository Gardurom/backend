<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'official_key' => $this->official_key,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => [
                'street' => $this->street,
                'external_number' => $this->external_number,
                'internal_number' => $this->internal_number,
                'neighborhood' => $this->neighborhood,
                'postal_code' => $this->postal_code,
                'locality' => $this->locality,
                'municipality' => $this->municipality,
                'state' => $this->state,
                'country_code' => $this->country_code,
            ],
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}