<?php

declare(strict_types=1);

namespace App\Services\PaymentProviders;

use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use App\Services\PaymentProviders\Paystack\PaystackProvider;
use App\Services\PaymentProviders\Cray\CrayProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ProviderFactory
{
    /**
     * Get the NAME of the currently active provider from the database.
     */
    public function getActiveProviderName(): string
    {
        // FIX: Cache ONLY the primitive string name, not the stdClass DB object
        $activeProviderName = Cache::remember('gateway_active_provider_name', 3600, function () {
            $provider = DB::table('providers')->where('is_active', true)->first();
            return $provider ? strtolower($provider->name) : null;
        });

        if (! $activeProviderName) {
            throw new RuntimeException('System Offline: No active payment provider is currently configured.');
        }

        return $activeProviderName;
    }

    /**
     * Instantiate a provider explicitly by name (Crucial for verifying older transactions).
     */
    public function make(string $providerName): PaymentProviderInterface
    {
        return match (strtolower($providerName)) {
            'paystack' => new PaystackProvider(),
            'cray' => new CrayProvider(),
            default => throw new RuntimeException("Integration for provider [{$providerName}] is not supported."),
        };
    }
}
