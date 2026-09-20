<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchMerchantWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookDeliveryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deliveries = $request->user()
            ->webhookDeliveries()
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $deliveries,
        ], Response::HTTP_OK);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $delivery = $request->user()
            ->webhookDeliveries()
            ->findOrFail($id);

        // Re-dispatch the webhook job
        DispatchMerchantWebhook::dispatch(
            $request->user(),
            $delivery->event,
            $delivery->payload['data'] ?? []
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook delivery retry dispatched.',
        ], Response::HTTP_OK);
    }
}
