<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller; // <-- FIX: This was missing
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Auth\Events\Verified;

class VerificationController extends Controller
{
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'merchant_id' => 'required|uuid',
            'code' => 'required|digits:6',
        ]);

        $merchant = Merchant::findOrFail($request->merchant_id);

        if ($merchant->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified. Proceed to login.'], 200);
        }

        $cachedOtp = Cache::get('otp_verify_' . $merchant->id);

        if (! $cachedOtp) {
            return response()->json(['message' => 'Verification code expired. Please request a new one.'], 400);
        }

        if (! hash_equals($cachedOtp, $request->code)) {
            return response()->json(['message' => 'Invalid verification code.'], 400);
        }

        $merchant->markEmailAsVerified();
        Cache::forget('otp_verify_' . $merchant->id);

        event(new Verified($merchant));

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully. You may now log in.'
        ], 200);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate(['merchant_id' => 'required|uuid']);

        $merchant = Merchant::find($request->merchant_id);

        if (! $merchant || $merchant->hasVerifiedEmail()) {
            return response()->json(['message' => 'Invalid request or email already verified.'], 400);
        }

        // Generate new code and cache for exactly 5 minutes
        $otp = (string) random_int(100000, 999999);
        Cache::put('otp_verify_' . $merchant->id, $otp, now()->addMinutes(5));

        $merchant->notify(new \App\Notifications\Auth\SendOtpNotification($otp));

        return response()->json([
            'status' => 'success',
            'message' => 'A new code has been sent.'
        ], 200);
    }
}
