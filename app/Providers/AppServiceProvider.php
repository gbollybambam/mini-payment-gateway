<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use App\Services\PaymentProviders\Paystack\PaystackProvider;
use App\Services\PaymentProviders\Cray\CrayProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentProviderInterface::class, function ($app) {
            return match (config('services.active_provider', 'paystack')) {
                'paystack' => new PaystackProvider(),
                default => new CrayProvider(),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}