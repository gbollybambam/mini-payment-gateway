<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'direction' => $this->direction->value,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            'description' => $this->description,
            'reference' => $this->reference,
            'transaction_id' => $this->transaction_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
