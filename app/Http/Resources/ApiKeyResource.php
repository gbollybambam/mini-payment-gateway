<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Fully mask the key for the table (e.g., sk_test_********************)
        $maskedToken = $this->key_prefix . '_********************';

        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'environment' => $this->environment,
            'token' => $maskedToken, // Always fully masked for the table view
            'scopes' => $this->scopes ?? ['*'],
            'expires_at' => $this->expires_at,
            'last_used_at' => $this->last_used_at,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at,
        ];

        if (! empty($this->plain_text_key)) {
            $data['plain_text_key'] = $this->plain_text_key;
        }

        return $data;
    }
}
