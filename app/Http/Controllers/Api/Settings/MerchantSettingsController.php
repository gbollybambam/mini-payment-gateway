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
    public function show(Request $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);

        $settings = $merchant->settings()->firstOrCreate(
            ['merchant_id' => $merchant->id],
            ['brand_name' => $merchant->business_name, 'webhook_secret' => 'whsec_' . Str::random(32)]
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

    public function update(UpdateMerchantSettingsRequest $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $validated = $request->validated();

        if (isset($validated['email']) && $validated['email'] !== $merchant->email) {
            $merchant->email = $validated['email'];
            $merchant->email_verified_at = null;
            $merchant->sendEmailVerificationNotification();
        }

        if (isset($validated['business_name']) || isset($validated['phone'])) {
            $merchant->fill($request->only(['business_name', 'phone']));
        }

        if ($merchant->isDirty()) {
            $merchant->save();
        }

        $settings = $merchant->settings()->firstOrCreate(['merchant_id' => $merchant->id]);
        $settings->update($request->except(['business_name', 'phone', 'email']));

        return response()->json([
            'status' => 'success',
            'message' => isset($validated['email'])
                ? 'Settings updated. Please verify your new email address.'
                : 'Settings updated successfully.',
        ], Response::HTTP_OK);
    }

    public function rotateWebhookSecret(Request $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $settings = $merchant->settings;

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

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // 1. Manually check the hash here where the user context is perfectly guaranteed
        if (! Hash::check($request->validated('current_password'), $user->password)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'current_password' => ['The provided password does not match your current password.']
                ]
            ], 422); // 422 triggers the frontend error state perfectly
        }

        // 2. Update the password
        $user->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password updated successfully.',
        ], Response::HTTP_OK);
    }
}
