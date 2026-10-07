<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentLinkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentLink\StorePaymentLinkRequest;
use App\Http\Requests\PaymentLink\UpdatePaymentLinkRequest;
use App\Http\Resources\PaymentLinkResource;
use App\Models\PaymentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PaymentLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $links = PaymentLink::where('merchant_id', $merchant->id)
            ->with('payment:id,customer_email,amount,created_at')
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => PaymentLinkResource::collection($links),
            'meta' => [
                'current_page' => $links->currentPage(),
                'last_page' => $links->lastPage(),
                'per_page' => $links->perPage(),
                'total' => $links->total(),
            ]
        ], Response::HTTP_OK);
    }

    public function store(StorePaymentLinkRequest $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $validated = $request->validated();

        $customerInformation = null;
        if (!empty($validated['customer_email'])) {
            $customerInformation = ['email' => $validated['customer_email']];
        }

        // Prevent mass-assignment error on the JSON column mapping
        unset($validated['customer_email']);

        $link = $merchant->paymentLinks()->create(
            array_merge($validated, [
                'customer_information' => $customerInformation,
                'checkout_token' => Str::random(32),
                'status' => PaymentLinkStatus::ACTIVE,
            ])
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Payment link created successfully.',
            'data' => new PaymentLinkResource($link),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $link = $merchant->paymentLinks()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new PaymentLinkResource($link),
        ], Response::HTTP_OK);
    }

    public function update(UpdatePaymentLinkRequest $request, string $id): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $link = $merchant->paymentLinks()->findOrFail($id);

        $link->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Payment link updated successfully.',
            'data' => new PaymentLinkResource($link),
        ], Response::HTTP_OK);
    }
}
