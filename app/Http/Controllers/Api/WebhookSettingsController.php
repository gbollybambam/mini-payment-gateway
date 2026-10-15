<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchMerchantWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;
use App\Support\SafeWebhookUrl;

class WebhookSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $settings = $merchant->settings;

        $maskedSecret = $settings?->webhook_secret
            ? substr($settings->webhook_secret, 0, 10) . '**********************'
            : null;

        return response()->json([
            'status' => 'success',
            'data' => [
                'webhook_url' => $settings?->webhook_url,
                'webhook_secret' => $maskedSecret,
            ],
        ], Response::HTTP_OK);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'webhook_url' => [
                'nullable',
                'url:https',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value && !SafeWebhookUrl::isSafe($value)) {
                        $fail('The webhook URL must use HTTPS and resolve only to a public address.');
                    }
                },
            ],
            'webhook_secret' => ['nullable', 'string', 'min:16'],
        ]);

        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $settings = $merchant->settings()->firstOrCreate(
            ['merchant_id' => $merchant->id],
            ['webhook_secret' => 'whsec_' . Str::random(32)]
        );

        $settings->update([
            'webhook_url' => $request->webhook_url,
        ]);

        $maskedSecret = $settings->webhook_secret
            ? substr($settings->webhook_secret, 0, 10) . '**********************'
            : null;

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook settings updated successfully.',
            'data' => [
                'webhook_url' => $settings->webhook_url,
                'webhook_secret' => $maskedSecret,
            ],
        ], Response::HTTP_OK);
    }

    public function test(Request $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $settings = $merchant->settings;

        if (! $settings || ! $settings->webhook_url || ! $settings->webhook_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please configure your webhook URL and secret before sending a test event.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DispatchMerchantWebhook::dispatch(
            $merchant,
            'test.ping',
            [
                'message' => 'This is a test webhook event from your gateway.',
                'timestamp' => now()->toIso8601String(),
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Test webhook dispatched successfully.',
        ], Response::HTTP_OK);
    }
}
