<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\KnownDevice;
use App\Models\LoginAudit;
use App\Models\Wallet;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterMerchantRequest;
use App\Http\Resources\MerchantResource;

use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(RegisterMerchantRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $validated = $request->validated();
        $payloadFingerprint = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        if ($idempotencyKey) {
            $cacheKey = 'idemp_reg_' . hash('sha256', $idempotencyKey);
            $cached = Cache::get($cacheKey);
            if ($cached) {
                if (is_array($cached) && ($cached['fingerprint'] ?? null) !== $payloadFingerprint) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This idempotency key was already used for different registration data.',
                    ], Response::HTTP_CONFLICT);
                }

                return is_array($cached) ? $cached['response'] : $cached;
            }
        }

        // Use a transaction to ensure atomic provisioning of Merchant, Settings, and Wallet
        $merchant = DB::transaction(function () use ($validated) {
            // 1. Check for an existing UNVERIFIED account (the typo trap fix)
            $merchant = Merchant::where('email', $validated['email'])
                ->whereNull('email_verified_at')
                ->first();

            if ($merchant) {
                // FIX: Prevent overwriting an account that is actively trying to verify right now
                if ($merchant->created_at->addMinutes(15)->isFuture()) {
                    return null;
                }
                // Safely overwrite the abandoned/typo record
                $merchant->update($validated);

                Wallet::updateOrCreate(
                    ['merchant_id' => $merchant->id],
                    ['currency' => $merchant->currency]
                );
            } else {
                // Completely new registration
                $merchant = Merchant::create($validated);

                $merchant->settings()->create([
                    'brand_name' => $merchant->business_name,
                    'webhook_secret' => 'whsec_' . Str::random(32),
                ]);

                Wallet::create([
                    'merchant_id' => $merchant->id,
                    'currency' => $merchant->currency,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                ]);
            }

            return $merchant;
        });

        if (! $merchant) {
            return response()->json([
                'status' => 'error',
                'message' => 'A registration is currently in progress for this email. Please check your inbox or wait 15 minutes to try again.',
            ], Response::HTTP_CONFLICT);
        }

        // 2. Generate a secure 6-digit OTP
        $otp = (string) random_int(100000, 999999);

        // 3. Cache the OTP for 5 minutes, tied directly to this merchant ID
        Cache::put('otp_verify_' . $merchant->id, $otp, now()->addMinutes(5));

        // 4. Dispatch the email
        $merchant->notify(new \App\Notifications\Auth\SendOtpNotification($otp));

        $response = response()->json([
            'status' => 'success',
            'message' => 'Registration successful. Please enter the OTP sent to your email.',
            'data' => [
                'merchant_id' => $merchant->id
            ]
        ], Response::HTTP_CREATED);

        if ($idempotencyKey) {
            Cache::put($cacheKey, [
                'fingerprint' => $payloadFingerprint,
                'response' => $response,
            ], now()->addHours(24));
        }

        return $response;
    }

    /**
     * Authenticate an existing merchant.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = strtolower(trim($request->input('email')));
        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown Device';

        // -------------------------------------------------------------
        // PATH A: Try authenticating as a Primary Merchant Owner
        // -------------------------------------------------------------
        $merchant = Merchant::where('email', $email)->first();

        if ($merchant) {
            // Password Check
            if (! Hash::check($request->password, $merchant->password)) {
                LoginAudit::create(['merchant_id' => $merchant->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'status' => 'failed_password']);
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }

            // Suspension Intercept
            if ((isset($merchant->is_active) && ! $merchant->is_active)
                || strtoupper($merchant->status ?? '') === 'SUSPENDED') {
                LoginAudit::create(['merchant_id' => $merchant->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'status' => 'suspended']);
                return response()->json([
                    'code' => 'account_suspended',
                    'message' => 'This account has been suspended. Please contact support.'
                ], 403);
            }

            // Unverified Intercept
            if (! $merchant->hasVerifiedEmail()) {
                $otp = (string) random_int(100000, 999999);
                Cache::put('otp_verify_' . $merchant->id, $otp, now()->addMinutes(5));
                $merchant->notify(new \App\Notifications\Auth\SendOtpNotification($otp));

                return response()->json([
                    'code' => 'email_unverified',
                    'message' => 'Please verify your email address.',
                    'data' => ['merchant_id' => $merchant->id]
                ], 403);
            }

            // Risk-Based Authentication (RBA) Engine
            $deviceSignature = hash('sha256', $ip . $userAgent);
            $knownDevice = KnownDevice::where('merchant_id', $merchant->id)
                ->whereNull('merchant_user_id')
                ->where('device_signature', $deviceSignature)
                ->first();

            if (! $knownDevice) {
                LoginAudit::create(['merchant_id' => $merchant->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'status' => 'challenged']);
                $challenge = $this->createDeviceChallenge($merchant, null, $ip, $userAgent);
                $merchant->notify(new \App\Notifications\Auth\DeviceVerificationNotification(
                    Cache::get('device_verify_' . $challenge)['otp'],
                    $ip,
                    $userAgent
                ));

                return response()->json([
                    'code' => 'device_unknown',
                    'message' => 'New device detected. Please enter the verification code sent to your email.',
                    'data' => ['challenge' => $challenge]
                ], 202);
            }

            $knownDevice->update(['last_login_at' => now()]);
            LoginAudit::create(['merchant_id' => $merchant->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'status' => 'success']);

            Auth::guard('web')->login($merchant);
            $request->session()->regenerate();

            return response()->json([
                'status' => 'success',
                'message' => 'Welcome back.'
            ], 200);
        }

        // -------------------------------------------------------------
        // PATH B: Try authenticating as a Team Member (MerchantUser)
        // -------------------------------------------------------------
        $teamUsers = MerchantUser::where('email', $email)->with('merchant')->get();
        $teamUser = $teamUsers->filter(
            fn (MerchantUser $candidate): bool => Hash::check($request->password, $candidate->password)
        )->values();

        if ($teamUser->count() === 1) {
            $teamUser = $teamUser->first();

            if (! $teamUser->is_active || ! $teamUser->merchant->is_active
                || strtoupper($teamUser->merchant->status ?? '') === 'SUSPENDED') {
                return response()->json(['code' => 'account_suspended', 'message' => 'Your team account has been deactivated.'], 403);
            }

            $deviceSignature = hash('sha256', $ip . $userAgent);
            $knownDevice = KnownDevice::where('merchant_id', $teamUser->merchant_id)
                ->where('merchant_user_id', $teamUser->id)
                ->where('device_signature', $deviceSignature)
                ->first();

            if (! $knownDevice) {
                LoginAudit::create([
                    'merchant_id' => $teamUser->merchant_id,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'status' => 'challenged',
                ]);
                $challenge = $this->createDeviceChallenge($teamUser->merchant, $teamUser, $ip, $userAgent);
                $teamUser->notify(new \App\Notifications\Auth\DeviceVerificationNotification(
                    Cache::get('device_verify_' . $challenge)['otp'],
                    $ip,
                    $userAgent
                ));

                return response()->json([
                    'code' => 'device_unknown',
                    'message' => 'New device detected. Please enter the verification code sent to your email.',
                    'data' => ['challenge' => $challenge],
                ], 202);
            }

            $knownDevice->update(['last_login_at' => now()]);
            Auth::guard('team')->login($teamUser);
            $request->session()->regenerate();

            return response()->json(['status' => 'success', 'message' => 'Welcome back.'], 200);
        }

        return response()->json(['message' => 'Invalid credentials.'], 401);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        // Logout from both guards to be safe
        Auth::guard('web')->logout();
        Auth::guard('team')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'success', 'message' => 'Logged out successfully.'], Response::HTTP_OK);
    }

    public function me(Request $request): JsonResponse
    {
        // CRITICAL FIX: Check the team guard first, then the web guard
        $user = Auth::guard('team')->user() ?? Auth::guard('web')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user instanceof \App\Models\MerchantUser) {
            $merchant = clone $user->merchant; // Get parent org
            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'account_type' => 'team_member',
                    'merchant_id' => $merchant->id,
                    'business_name' => $merchant->business_name,
                ]
            ], Response::HTTP_OK);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'name' => $user->business_name,
                'email' => $user->email,
                'role' => 'owner',
                'account_type' => 'merchant',
                'merchant_id' => $user->id,
                'business_name' => $user->business_name,
            ]
        ], Response::HTTP_OK);
    }

    private function createDeviceChallenge(
        Merchant $merchant,
        ?MerchantUser $teamUser,
        string $ip,
        string $userAgent
    ): string {
        $challenge = Str::random(64);
        $otp = (string) random_int(100000, 999999);

        Cache::put('device_verify_' . $challenge, [
            'otp' => $otp,
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $teamUser?->id,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ], now()->addMinutes(10));

        return $challenge;
    }
}
