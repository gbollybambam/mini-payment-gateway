<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginMerchantRequest;
use App\Http\Requests\Auth\RegisterMerchantRequest;
use App\Http\Resources\MerchantResource;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;


class AuthController extends Controller
{
    /**
     * Register a new merchant
     */
    public function register(RegisterMerchantRequest $request): JsonResponse
    {
        // 1. Check if the frontend sent an Idempotency Key
        $idempotencyKey = $request->header('Idempotency-Key');
        $cacheKey = 'idemp_reg_' . $idempotencyKey;

        // 2. If we already processed this exact request successfully, return the cached response immediately!
        if ($idempotencyKey && \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        // 3. Normal Registration Logic
        $merchant = \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $merchant = Merchant::create($request->validated());

            $merchant->settings()->create([
                'brand_name' => $merchant->business_name,
                'webhook_secret' => 'whsec_' . \Illuminate\Support\Str::random(32),
                'cray_subaccount_token' => 'cray_sub_' . \Illuminate\Support\Str::random(24),
            ]);

            \App\Models\Wallet::create([
                'merchant_id' => $merchant->id,
                'currency' => $merchant->currency,
                'available_balance' => 0,
                'pending_balance' => 0,
            ]);

            return $merchant;
        });

        $merchant->sendEmailVerificationNotification();

        Auth::guard('web')->login($merchant);
        $request->session()->regenerate();

        $response = response()->json([
            'status' => 'success',
            'message' => 'Merchant registered successfully.',
            'data' => new MerchantResource($merchant),
        ], Response::HTTP_CREATED);

        // 4. Save the successful response in the cache for 24 hours
        if ($idempotencyKey) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $response, now()->addHours(24));
        }

        return $response;
    }

    /**
     * Authenticate an existing merchant.
     */
    public function login(LoginMerchantRequest $request): JsonResponse
    {
        // Auth::attempt securely hashes and checks the password, and sets the HttpOnly cookie
        if (Auth::guard('web')->attempt($request->only('email', 'password'))) {
            $request->session()->regenerate();

            return response()->json([
                'status' => 'success',
                'message' => 'Authentication successful.',
                'data' => new MerchantResource(Auth::guard('web')->user()),
            ], Response::HTTP_OK);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid email or password provided.',
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ], Response::HTTP_OK);
    }

    /**
     * Get the authenticated merchant profile
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => new MerchantResource($request->user()),
        ], Response::HTTP_OK);
    }
}
