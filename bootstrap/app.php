<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Updated for Laravel 11 modern syntax (fixes deprecation warning)
        $middleware->preventRequestForgery(except: [
            'api/webhooks/*',
        ]);

        $middleware->alias([
            'auth.api_key' => \App\Http\Middleware\VerifyApiKey::class,
            'merchant.active' => \App\Http\Middleware\EnsureMerchantIsActive::class,
        ]);

        // This enables Sanctum cookie sessions for your frontend
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();