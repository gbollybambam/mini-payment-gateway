<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\KnownDevice;
use App\Models\LoginAudit;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Notifications\Auth\DeviceVerificationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class DeviceVerificationController extends Controller
{
    public function verifyDevice(Request $request): JsonResponse
    {
        $request->validate([
            'challenge' => 'required|string|size:64',
            'code' => 'required|digits:6',
        ]);

        $cacheKey = 'device_verify_' . $request->string('challenge')->toString();
        $challenge = Cache::get($cacheKey);
        if (! is_array($challenge)) {
            return response()->json(['message' => 'Verification code expired. Please request a new one.'], 400);
        }

        if (! hash_equals($challenge['otp'], $request->string('code')->toString())) {
            return response()->json(['message' => 'Invalid verification code.'], 400);
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown Device';
        if ($challenge['ip_address'] !== $ip || $challenge['user_agent'] !== $userAgent) {
            return response()->json(['message' => 'This device verification request is no longer valid. Please log in again.'], 400);
        }

        $merchant = Merchant::findOrFail($challenge['merchant_id']);
        if (! $merchant->is_active || strtoupper($merchant->status ?? '') === 'SUSPENDED') {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'This merchant account is suspended.'], 403);
        }

        $teamUser = $challenge['merchant_user_id']
            ? MerchantUser::where('merchant_id', $merchant->id)->findOrFail($challenge['merchant_user_id'])
            : null;
        if ($teamUser && ! $teamUser->is_active) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'This team account is deactivated.'], 403);
        }

        $deviceSignature = hash('sha256', $ip . $userAgent);
        KnownDevice::create([
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $teamUser?->id,
            'device_signature' => $deviceSignature,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'last_login_at' => now(),
        ]);

        LoginAudit::create([
            'merchant_id' => $merchant->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'success',
        ]);

        // Pull makes a successful challenge one-time, including under concurrent requests.
        if (! is_array(Cache::pull($cacheKey))) {
            return response()->json(['message' => 'Verification code expired. Please request a new one.'], 400);
        }
        Auth::guard($teamUser ? 'team' : 'web')->login($teamUser ?: $merchant);
        $request->session()->regenerate();

        return response()->json([
            'status' => 'success',
            'message' => 'Device verified successfully. Welcome back.',
        ]);
    }

    public function resendDeviceOtp(Request $request): JsonResponse
    {
        $request->validate(['challenge' => 'required|string|size:64']);

        $cacheKey = 'device_verify_' . $request->string('challenge')->toString();
        $challenge = Cache::get($cacheKey);
        if (! is_array($challenge)) {
            return response()->json(['message' => 'Verification request expired. Please log in again.'], 400);
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown Device';
        if ($challenge['ip_address'] !== $ip || $challenge['user_agent'] !== $userAgent) {
            return response()->json(['message' => 'This device verification request is no longer valid. Please log in again.'], 400);
        }

        $otp = (string) random_int(100000, 999999);
        $challenge['otp'] = $otp;
        Cache::put($cacheKey, $challenge, now()->addMinutes(10));

        ($challenge['merchant_user_id']
            ? MerchantUser::findOrFail($challenge['merchant_user_id'])
            : Merchant::findOrFail($challenge['merchant_id'])
        )->notify(new DeviceVerificationNotification($otp, $ip, $userAgent));

        return response()->json([
            'status' => 'success',
            'message' => 'A new device verification code has been sent.',
        ]);
    }
}
