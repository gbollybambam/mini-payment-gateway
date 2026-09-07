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

class AuthController extends Controller
{
    /**
     * Register a new merchant
     */
    public function register(RegisterMerchantRequest $request): JsonResponse
    {
        $merchant = Merchant::create($request->validated());

        // Initialize default merchant settings record
        $merchant->settings()->create([
            'brand_name' => $merchant->business_name,
            'webhook_secret' => 'whsec_' . \Illuminate\Support\Str::random(32),
        ]);

        // Send verification email
        $merchant->sendEmailVerificationNotification();

        $token = $merchant->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Merchant registered successfully. Please verify your email.',
            'data' => [
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Authenticate an existing merchant.
     */
    public function login(LoginMerchantRequest $request): JsonResponse
    {
        $merchant = Merchant::where('email', $request->validated('email'))->first();

        if (! $merchant || ! Hash::check($request->validated('password'), $merchant->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid email or password provided.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = $merchant->createToken('auth-token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Authentication successful.',
            'data' => [
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' =>  'success',
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
