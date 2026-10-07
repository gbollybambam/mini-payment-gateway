<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\KnownDevice;
use App\Models\LoginAudit;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Notifications\Auth\DeviceVerificationNotification;

class DeviceVerificationController extends Controller
{
    public function verifyDevice(Request $request): JsonResponse
    {
        $request->validate([
            'merchant_id' => 'required|uuid',
            'code' => 'required|digits:6',
        ]);

        $merchant = Merchant::findOrFail($request->merchant_id);
        $cachedOtp = Cache::get('device_verify_' . $merchant->id);

        if (! $cachedOtp) {
            return response()->json(['message' => 'Verification code expired. Please request a new one.'], 400);
        }

        if (! hash_equals($cachedOtp, $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 400);
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown Device';
        $deviceSignature = hash('sha256', $ip . $userAgent);

        // 1. Register the new device as trusted
        KnownDevice::create([
            'merchant_id' => $merchant->id,
            'device_signature' => $deviceSignature,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'last_login_at' => now(),
        ]);

        // 2. Log the successful audit
        LoginAudit::create([
            'merchant_id' => $merchant->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'success'
        ]);

        // 3. Clean up the cache
        Cache::forget('device_verify_' . $merchant->id);

        // 4. Log the merchant in and secure the session
        Auth::guard('web')->login($merchant);
        $request->session()->regenerate();

        return response()->json([
            'status' => 'success',
            'message' => 'Device verified successfully. Welcome back.'
        ], 200);
    }

    public function resendDeviceOtp(Request $request): JsonResponse
    {
        $request->validate(['merchant_id' => 'required|uuid']);

        $merchant = Merchant::findOrFail($request->merchant_id);
        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown Device';

        // Generate new 10-minute code
        $otp = (string) random_int(100000, 999999);
        Cache::put('device_verify_' . $merchant->id, $otp, now()->addMinutes(10));

        $merchant->notify(new DeviceVerificationNotification($otp, $ip, $userAgent));

        return response()->json([
            'status' => 'success',
            'message' => 'A new device verification code has been sent.'
        ], 200);
    }
}
