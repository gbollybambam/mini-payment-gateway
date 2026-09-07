<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * Generate a securely hashed API key while returning the plaintext version once.
     */

    public function generate(Merchant $merchant, string $name, string $environment): array
    {
        $prefix = $environment === 'production' ? 'sk_live_' : 'sk_test_';
        $plainTextKey = $prefix . Str::random(40);

        $apiKey = $merchant->apiKeys()->create([
            'name' => $name,
            'environment' => $environment,
            'key_prefix' => substr($plainTextKey, 0, 12),
            'secret_key' => hash('sha256', $plainTextKey),
        ]);

        return [
            'api_key' => $apiKey,
            'plain_text_key' => $plainTextKey,
        ];
    }

    /**
     * Rotate an API key by generating a new secret for the exiting record.
     */
    public function rotate(ApiKey $apiKey): string
    {
        $prefix = $apiKey->environment === 'production' ? 'sk_live_' : 'sk_test_';
        $plainTextKey = $prefix . Str::random(40);

        $apiKey->update([
            'key_prefix' => substr($plainTextKey, 0, 12),
            'secret_key' => hash('sha256', $plainTextKey),
        ]);

        return $plainTextKey;
    }
}