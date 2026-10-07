<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $frontendBaseUrl = config('app.frontend_url', 'https://pay.example.com');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'status' => $this->status->value,
            'customer_information' => $this->customer_information,
            'checkout_token' => $this->checkout_token,

            // PRD Section 8: The system generates a unique URL such as https://pay.example.com/pay/{token}
            'checkout_url' => "{$frontendBaseUrl}/pay/{$this->checkout_token}",

            'redirect_url' => $this->redirect_url,
            'expiry' => $this->expiry?->toIso8601String(),
            'metadata' => $this->metadata,
            'payment_id' => $this->payment_id,

            // THE FIX: Securely expose the payer details only when eager-loaded
            'payment' => $this->whenLoaded('payment', function () {
                return [
                    'customer_email' => $this->payment?->customer_email,
                    'amount' => (float) ($this->payment?->amount ?? 0),
                    'created_at' => $this->payment?->created_at?->toIso8601String(),
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
