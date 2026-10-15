<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Settings\MerchantSettingsController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\VerificationController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\Api\WebhookSettingsController;
use App\Http\Controllers\Api\WebhookDeliveryController;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:25,1');

    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1');

    Route::post('/email/verify-otp', [VerificationController::class, 'verifyOtp'])->middleware('throttle:5,1');
    Route::post('/email/resend-otp', [VerificationController::class, 'resendOtp'])->middleware('throttle:3,1');

    Route::post('/device/verify', [\App\Http\Controllers\Api\Auth\DeviceVerificationController::class, 'verifyDevice'])->middleware('throttle:5,1');
    Route::post('/device/resend', [\App\Http\Controllers\Api\Auth\DeviceVerificationController::class, 'resendDeviceOtp'])->middleware('throttle:5,1');

    Route::post('/team/accept-invite', [\App\Http\Controllers\Api\Auth\TeamInviteController::class, 'accept']);
    Route::post('/team/{id}/resend', [\App\Http\Controllers\Api\TeamController::class, 'resendInvite']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::delete('/sessions/{id}', [SessionController::class, 'revoke']);
        Route::post('/sessions/revoke-others', [SessionController::class, 'revokeOthers']);
    });
});

/*
|--------------------------------------------------------------------------
| Merchant Authenticated Surface (UI API)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'merchant.active'])->group(function () {

    // -----------------------------------------------------------------
    // EVERYONE (Owner, Admin, Developer, Finance)
    // -----------------------------------------------------------------
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/transactions', [\App\Http\Controllers\Api\TransactionController::class, 'index']);
    Route::post('/transactions/{id}/verify', [\App\Http\Controllers\Api\V1\PaymentController::class, 'verify'])->middleware('throttle:5,1');

    Route::get('/payment-links', [\App\Http\Controllers\Api\PaymentLinkController::class, 'index']);
    Route::post('/payment-links', [\App\Http\Controllers\Api\PaymentLinkController::class, 'store']);
    Route::get('/payment-links/{id}', [\App\Http\Controllers\Api\PaymentLinkController::class, 'show']);
    Route::patch('/payment-links/{id}', [\App\Http\Controllers\Api\PaymentLinkController::class, 'update']);

    // UI Dependencies: Everyone needs to read Settings and Wallet basic details to load pages properly
    Route::get('/settings', [MerchantSettingsController::class, 'show']);
    Route::match(['put', 'patch'], '/user/password', [MerchantSettingsController::class, 'updatePassword']);
    Route::get('/wallet', [\App\Http\Controllers\Api\WalletController::class, 'index']);
    Route::get('/wallet/balance', [\App\Http\Controllers\Api\WalletController::class, 'index']);

    // -----------------------------------------------------------------
    // TECHNICAL DATA (Owner, Admin, Developer)
    // -----------------------------------------------------------------
    Route::middleware('role:admin,developer')->group(function () {
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
        Route::post('/api-keys/{id}/rotate', [ApiKeyController::class, 'rotate']);

        Route::get('/webhook-settings', [WebhookSettingsController::class, 'show']);
        Route::put('/webhook-settings', [WebhookSettingsController::class, 'update']);
        Route::post('/webhook-settings/test', [WebhookSettingsController::class, 'test']);
        Route::get('/webhook-deliveries', [WebhookDeliveryController::class, 'index']);
        Route::post('/webhook-deliveries/{id}/retry', [WebhookDeliveryController::class, 'retry']);
    });

    // -----------------------------------------------------------------
    // MONEY MOVEMENT (Owner, Admin, Finance)
    // -----------------------------------------------------------------
    Route::middleware('role:admin,finance')->group(function () {
        Route::get('/wallet/ledger', [\App\Http\Controllers\Api\WalletController::class, 'ledger']);

        Route::get('/withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'initialize']);
        Route::get('/withdrawals/banks', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'getBanks']);
        Route::get('/withdrawals/resolve-account', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'resolveAccount']);
        Route::get('/withdrawals/{id}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'show']);
        Route::post('/withdrawals/{id}/verify', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'verify'])->middleware('throttle:10,1');
    });

    // -----------------------------------------------------------------
    // CORE OPERATIONS (Owner, Admin)
    // -----------------------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get('/team', [\App\Http\Controllers\Api\TeamController::class, 'index']);
        Route::post('/team', [\App\Http\Controllers\Api\TeamController::class, 'store']);
        Route::patch('/team/{id}', [\App\Http\Controllers\Api\TeamController::class, 'update']);
        Route::delete('/team/{id}', [\App\Http\Controllers\Api\TeamController::class, 'destroy']);

        Route::put('/settings', [MerchantSettingsController::class, 'update']);
        Route::post('/settings/webhook-secret/rotate', [MerchantSettingsController::class, 'rotateWebhookSecret']);
    });
});

/*
|--------------------------------------------------------------------------
| External Merchant API (Protected by Secret API Keys & Scopes)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['auth.api_key', 'merchant.active'])->group(function () {

    // Payment Endpoints
    Route::get('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'index'])
        ->middleware('scope:payments:read');

    Route::post('/payments', [\App\Http\Controllers\Api\V1\PaymentController::class, 'initialize'])
        ->middleware('scope:payments:write');

    Route::get('/payments/{id}/verify', [\App\Http\Controllers\Api\V1\PaymentController::class, 'verify'])
        ->middleware('scope:payments:read');

    Route::get('/payments/{id}', [\App\Http\Controllers\Api\V1\PaymentController::class, 'show'])
        ->middleware('scope:payments:read');

    // Withdrawal Endpoints
    Route::get('/withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'index'])
        ->middleware('scope:withdrawals:read');

    Route::post('/withdrawals', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'initialize'])
        ->middleware('scope:withdrawals:write');

    Route::get('/withdrawals/{id}', [\App\Http\Controllers\Api\V1\WithdrawalController::class, 'show'])
        ->middleware('scope:withdrawals:read');
});

/*
|--------------------------------------------------------------------------
| Public Hosted Checkout API & Webhooks
|--------------------------------------------------------------------------
*/
Route::prefix('checkout')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Api\CheckoutController::class, 'show']);
    Route::post('/{token}', [\App\Http\Controllers\Api\CheckoutController::class, 'process']);
    Route::get('/{token}/callback', [\App\Http\Controllers\Api\CheckoutController::class, 'callback']);
});

Route::post('/webhooks/{provider}', [\App\Http\Controllers\Api\WebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Admin Backoffice API
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('/login', [\App\Http\Controllers\Admin\AuthController::class, 'login']);

    Route::middleware('auth:admin')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'overview']);
        Route::get('/merchants', [\App\Http\Controllers\Admin\MerchantController::class, 'index']);
        Route::get('/merchants/{id}', [\App\Http\Controllers\Admin\MerchantController::class, 'show']);
        Route::patch('/merchants/{id}/toggle-status', [\App\Http\Controllers\Admin\MerchantController::class, 'toggleStatus']);
        Route::post('/merchants/{id}/adjust-balance', [\App\Http\Controllers\Admin\MerchantController::class, 'adjustBalance']);
        Route::get('/transactions', [\App\Http\Controllers\Admin\TransactionController::class, 'transactions']);
        Route::get('/withdrawals', [\App\Http\Controllers\Admin\TransactionController::class, 'withdrawals']);
        Route::get('/providers', [\App\Http\Controllers\Admin\ProviderController::class, 'index']);
        Route::patch('/providers/{id}', [\App\Http\Controllers\Admin\ProviderController::class, 'update']);
        Route::get('/audit-logs', [\App\Http\Controllers\Admin\AuditLogController::class, 'index']);
    });
});
