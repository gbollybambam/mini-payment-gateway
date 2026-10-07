<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\Merchant;
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

        if ($idempotencyKey) {
            $cacheKey = 'idemp_reg_' . hash('sha256', $idempotencyKey);
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        }

        // Use a transaction to ensure atomic provisioning of Merchant, Settings, and Wallet
        $merchant = DB::transaction(function () use ($request) {
            // 1. Check for an existing UNVERIFIED account (the typo trap fix)
            $merchant = Merchant::where('email', $request->email)
                ->whereNull('email_verified_at')
                ->first();

            if ($merchant) {
                // FIX: Prevent overwriting an account that is actively trying to verify right now
                if ($merchant->created_at->addMinutes(15)->isFuture()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'A registration is currently in progress for this email. Please check your inbox or wait 15 minutes to try again.'
                    ], Response::HTTP_CONFLICT);
                }
                // Safely overwrite the abandoned/typo record
                $merchant->update($request->validated());
            } else {
                // Completely new registration
                $merchant = Merchant::create($request->validated());

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

        // 2. Generate a secure 6-digit OTP
        $otp = (string) random_int(100000, 999999);

        // 3. Cache the OTP for 15 minutes, tied directly to this merchant ID
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
            Cache::put($cacheKey ?? '', $response, now()->addHours(24));
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

        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? 'Unknown Device';

        // -------------------------------------------------------------
        // PATH A: Try authenticating as a Primary Merchant Owner
        // -------------------------------------------------------------
        $merchant = Merchant::where('email', $request->email)->first();

        if ($merchant) {
            // Password Check
            if (! Hash::check($request->password, $merchant->password)) {
                LoginAudit::create(['merchant_id' => $merchant->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'status' => 'failed_password']);
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }

            // Suspension Intercept
            if (isset($merchant->is_active) && ! $merchant->is_active) {
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
                ->where('device_signature', $deviceSignature)
                ->first();

            if (! $knownDevice) {
                LoginAudit::create(['merchant_id' => $merchant->id, 'ip_address' => $ip, 'user_agent' => $userAgent, 'status' => 'challenged']);
                $otp = (string) random_int(100000, 999999);
                Cache::put('device_verify_' . $merchant->id, $otp, now()->addMinutes(10));
                $merchant->notify(new \App\Notifications\Auth\DeviceVerificationNotification($otp, $ip, $userAgent));

                return response()->json([
                    'code' => 'device_unknown',
                    'message' => 'New device detected. Please enter the verification code sent to your email.',
                    'data' => ['merchant_id' => $merchant->id]
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
        $teamUser = \App\Models\MerchantUser::where('email', $request->email)->first();

        if ($teamUser) {
            if (! Hash::check($request->password, $teamUser->password)) {
                return response()->json(['message' => 'Invalid credentials.'], 401);
            }

            if (! $teamUser->is_active) {
                return response()->json(['code' => 'account_suspended', 'message' => 'Your team account has been deactivated.'], 403);
            }

            // CRITICAL FIX: Log them into the 'team' guard!
            Auth::guard('team')->login($teamUser);
            $request->session()->regenerate();

            return response()->json(['status' => 'success', 'message' => 'Welcome back.'], 200);
        }

        return response()->json(['message' => 'Invalid credentials.'], 401);
    }

    public function logout(Request $request): JsonResponse
    {
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
}
