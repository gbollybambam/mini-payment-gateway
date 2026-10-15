<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: [
            'api/webhooks/*',
        ]);

    $middleware->alias([
        'auth.api_key' => \App\Http\Middleware\VerifyApiKey::class,
        'merchant.active' => \App\Http\Middleware\EnsureMerchantIsActive::class,
        'role' => \App\Http\Middleware\CheckRole::class,
        'scope' => \App\Http\Middleware\CheckApiScope::class, // <-- ADD THIS LINE
    ]);

        $middleware->statefulApi();
        $middleware->prepend(\App\Http\Middleware\RejectUntrustedOrigins::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                report($exception);

                return response()->json([
                    'status' => 'error',
                    'message' => 'The service is temporarily unavailable. Please try again.',
                ], 503);
            }
        });
    })->create();
