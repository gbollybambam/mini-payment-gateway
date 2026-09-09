<?php

namespace App\Http\Resources\Checkout;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckoutSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->merchant->settings;

        return [
            'checkout_token' => $this->checkout_token,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'customer_information' => $this->customer_information,
            'status' => $this->status->value,
            
            'merchant' => [
                'business_name' => $this->merchant->business_name,
                'brand_name' => $settings?->brand_name ?? $this->merchant->business_name,
                'logo_url' => $settings?->checkout_logo_url ?? $settings?->logo_url,
            ],
        ];
    }
}
