<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'customer_email' => $this->customer_email,

            // Financials
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'fee' => (float) $this->fee,
            'net_amount' => (float) $this->net_amount,

            'status' => $this->status->value,
            'payment_method' => $this->payment_method,
            'checkout_url' => $this->checkout_url,
            'metadata' => $this->metadata,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
