<?php

namespace App\Providers;

use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use App\Services\PaymentProviders\Cray\CrayProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProviderInterface::class, CrayProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
