<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'currency' => $this->currency,
            'business_type' => $this->business_type,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
