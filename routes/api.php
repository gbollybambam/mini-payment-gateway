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
Route::middleware(['auth:sanctum', 'merchant.active'])->group(function () {
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

    // Dashboard: Payment Links Management
    Route::get('/payment-links', [\App\Http\Controllers\Api\PaymentLinkController::class, 'index']);
    Route::post('/payment-links', [\App\Http\Controllers\Api\PaymentLinkController::class, 'store']);
    Route::get('/payment-links/{id}', [\App\Http\Controllers\Api\PaymentLinkController::class, 'show']);
    Route::patch('/payment-links/{id}', [\App\Http\Controllers\Api\PaymentLinkController::class, 'update']);

    // Wallet & Ledger
    Route::get('/wallet', [\App\Http\Controllers\Api\WalletController::class, 'index']);
    Route::get('/wallet/balance', [\App\Http\Controllers\Api\WalletController::class, 'index']); // Resolves to the same data
    Route::get('/wallet/ledger', [\App\Http\Controllers\Api\WalletController::class, 'ledger']);
});


/*
|--------------------------------------------------------------------------
| External Merchant API (Protected by Secret API Keys)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['auth.api_key', 'merchant.active'])->group(function () {
    Route::get('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'index']);
    Route::post('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'initialize']);
    Route::get('/payments/{id}/verify', [\App\Http\Controllers\Api\V1\PaymentController::class, 'verify']);
    Route::get('/payments/{id}', [\App\Http\Controllers\Api\V1\PaymentController::class, 'show']);

    // Withdrawals (Protected by API Key)
    Route::get('/withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'index']);
    Route::post('/withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'initialize']);
    Route::get('/withdrawals/{id}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'show']);
});


/*
|--------------------------------------------------------------------------
| Public Hosted Checkout API (No auth required)
|--------------------------------------------------------------------------
*/
Route::prefix('checkout')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Api\CheckoutController::class, 'show']);
    Route::post('/{token}', [\App\Http\Controllers\Api\CheckoutController::class, 'process']);
    Route::get('/{token}/callback', [\App\Http\Controllers\Api\CheckoutController::class, 'callback']);
});

/*
|--------------------------------------------------------------------------
| Provider Webhooks (Unauthenticated, Secured by Signature)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/{provider}', [\App\Http\Controllers\Api\WebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Admin Backoffice API (Protected by Admin Auth Guard)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    // Public admin auth
    Route::post('/login', [\App\Http\Controllers\Admin\AuthController::class, 'login']);

    // Protected admin routes
    Route::middleware('auth:admin')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'overview']);

        // Merchant Management
        Route::get('/merchants', [\App\Http\Controllers\Admin\MerchantController::class, 'index']);
        Route::get('/merchants/{id}', [\App\Http\Controllers\Admin\MerchantController::class, 'show']);
        Route::patch('/merchants/{id}/toggle-status', [\App\Http\Controllers\Admin\MerchantController::class, 'toggleStatus']);

        // System Financial Views
        Route::get('/transactions', [\App\Http\Controllers\Admin\TransactionController::class, 'transactions']);
        Route::get('/withdrawals', [\App\Http\Controllers\Admin\TransactionController::class, 'withdrawals']);

        // Provider Management
        Route::get('/providers', [\App\Http\Controllers\Admin\ProviderController::class, 'index']);
        Route::patch('/providers/{id}', [\App\Http\Controllers\Admin\ProviderController::class, 'update']);

        // Audit Logs
        Route::get('/audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index']);
    });
});