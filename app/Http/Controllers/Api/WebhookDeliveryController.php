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
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $deliveries = $merchant->webhookDeliveries()
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $deliveries,
        ], Response::HTTP_OK);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $delivery = $merchant->webhookDeliveries()->findOrFail($id);

        // Re-dispatch the webhook job using the correct Parent Merchant
        DispatchMerchantWebhook::dispatch(
            $merchant,
            $delivery->event,
            $delivery->payload['data'] ?? []
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Webhook delivery retry dispatched.',
        ], Response::HTTP_OK);
    }
}
