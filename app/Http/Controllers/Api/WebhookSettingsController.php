<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchMerchantWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class WebhookSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $merchant = $request->user();
        $settings = $merchant->settings;

        return response()->json([
            'status' => 'success',
            'data' => [
                'webhook_url' => $settings?->webhook_url,
                'webhook_secret' => $settings?->webhook_secret,
            ],
        ], Response::HTTP_OK);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'webhook_url' => ['nullable', 'url'],
            'webhook_secret' => ['nullable', 'string', 'min:16'],
        ]);

        $merchant = $request->user();

        $settings = $merchant->settings()->firstOrCreate(
            ['merchant_id' => $merchant->id],
            ['webhook_secret' => 'whsec_' . Str::random(32)]
        );

        $settings->update([
            'webhook_url' => $request->webhook_url,
            'webhook_secret' => $request->webhook_secret ?? $settings->webhook_secret,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook settings updated successfully.',
            'data' => [
                'webhook_url' => $settings->webhook_url,
                'webhook_secret' => $settings->webhook_secret,
            ],
        ], Response::HTTP_OK);
    }

    public function test(Request $request): JsonResponse
    {
        $merchant = $request->user();
        $settings = $merchant->settings;

        if (! $settings || ! $settings->webhook_url || ! $settings->webhook_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please configure your webhook URL and secret before sending a test event.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Dispatch a test webhook event
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
