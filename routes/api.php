<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\VerificationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Settings\MerchantSettingsController;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Authentication & Password Resets (Rate-Limited)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Password Reset
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

    // Email Verification Endpoint
    Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
        ->name('verification.verify')
        ->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/email/verification-notification', [VerificationController::class, 'resend'])->middleware('throttle:6,1');

        // Session Management
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{id}', [SessionController::class, 'revoke']);
        Route::post('/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
    });
});

/*
|--------------------------------------------------------------------------
| Merchant Authenticated Surface
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    // Dashboard Overview
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);

    // API Key Management
    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/api-keys', [ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
    Route::post('/api-keys/{id}/rotate', [ApiKeyController::class, 'rotate']);

    // Merchant Settings
    Route::get('/settings', [MerchantSettingsController::class, 'show']);
    Route::put('/settings', [MerchantSettingsController::class, 'update']);
    Route::put('/settings/password', [MerchantSettingsController::class, 'updatePassword']);
    Route::post('/settings/webhook-secret/rotate', [MerchantSettingsController::class, 'rotateWebhookSecret']);
});


/*
|--------------------------------------------------------------------------
| External Merchant API (Protected by Secret API Keys)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware('auth.api_key')->group(function () {
    Route::get('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'index']);
    Route::post('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'initialize']);
    Route::get('/payments/{id}/verify', [\App\Http\Controllers\Api\V1\PaymentController::class, 'verify']);
    Route::get('/payments/{id}', [\App\Http\Controllers\Api\V1\PaymentController::class, 'show']);
});


/*
|--------------------------------------------------------------------------
| Provider Webhooks (Unauthenticated, Secured by Signature)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/{provider}', [\App\Http\Controllers\Api\WebhookController::class, 'handle']);