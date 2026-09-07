<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
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
            'name' => $this->name,
            'environment' => $this->environment,
            'key_prefix' => $this->key_prefix,
            'last_used_at' => $this->last_used_at,
            'revoked_at' => $this->revoked_at,
            'created_at' => $this->created_at?->toIso8601String(),

            $this->mergeWhen(isset($this->plain_text_key), [
                'secret_key' => $this->plain_text_key,
            ]),
        ];
    }
}
