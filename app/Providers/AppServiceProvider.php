<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\PaymentProviders\ProviderFactory;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1. Bind the factory as a singleton
        $this->app->singleton(ProviderFactory::class, function () {
            return new ProviderFactory();
        });

        // 2. Bind the Interface to automatically resolve to the ACTIVE database provider
        $this->app->bind(PaymentProviderInterface::class, function ($app) {
            $factory = $app->make(ProviderFactory::class);

            try {
                // Determine the active provider from the database
                $activeProviderName = $factory->getActiveProviderName();
                return $factory->make($activeProviderName);
            } catch (RuntimeException $e) {
                // If no active provider exists in the DB, we throw an exception
                // which the controller will catch and handle gracefully.
                throw new \Exception($e->getMessage(), 503);
            }
        });
    }

    public function boot(): void
    {
        //
    }
}
