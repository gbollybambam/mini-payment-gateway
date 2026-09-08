<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateMerchantSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use App\Http\Requests\Settings\UpdatePasswordRequest;
use Illuminate\Support\Facades\Hash;

class MerchantSettingsController extends Controller
{
    /**
     * Get merchant settings.
     */
    public function show(Request $request):JsonResponse
    {
        $merchant = $request->user();
        $settings = $merchant->settings()->firstOrCreate(
            ['merchant_id' => $merchant->id],
            ['brand_name' => $merchant->business_name, 'webhooh_secret' => 'whsec_' . Str::random(32)]
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'business' => [
                    'business_name' => $merchant->business_name,
                    'email' => $merchant->email,
                    'phone' => $merchant->phone,
                    'country' => $merchant->country,
                    'currency' => $merchant->currency,
                    'logo_url' => $settings->logo_url,
                    'website_url' => $settings->website_url,
                ],
                'checkout' => [
                    'brand_name' => $settings->brand_name,
                    'checkout_logo_url' => $settings->checkout_logo_url,
                    'success_url' => $settings->success_url,
                    'cancel_url' => $settings->cancel_url,
                ],
                'developer' => [
                    'webhook_url' => $settings->webhook_url,
                    'webhook_secret' => $settings->webhook_secret,
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Update settings.  
     */
    public function update(UpdateMerchantSettingsRequest $request): JsonResponse
    {
        $merchant = $request->user();
        $validated = $request->validated();

        // Update core merchant table fields if present
        if (isset($validated['business_name']) || isset($validated['phone'])) {
            $merchant->update($request->only(['business_name', 'phone']));
        }

        // Update settings table
        $settings = $merchant->settings()->firstOrCreate(['merchant_id' => $merchant->id]);
        $settings->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully.',
        ], Response::HTTP_OK);
    }

    /**
     * Rotate webhook secret.
     */
    public function rotateWebhookSecret(Request $request): JsonResponse
    {
        $settings = $request->user()->settings;
        $newSecret = 'whsec_' . Str::random(32);
        $settings->update(['webhook_secret' => $newSecret]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook secret rotated successfully.',
            'data' => [
                'webhook_secret' => $newSecret,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Update authenticated merchant's password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.',
        ], Response::HTTP_OK);
    }
}
