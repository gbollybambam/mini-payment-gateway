<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\ProcessCheckoutRequest;
use App\Http\Resources\Checkout\CheckoutSessionResource;
use App\Models\PaymentLink;
use App\Models\Transaction;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController extends Controller
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider
    ) {}

    /**
     * Display the payment link details for the customer.
     */
    public function show(string $token): JsonResponse
    {
        $link = PaymentLink::with(['merchant.settings'])
            ->where('checkout_token', $token)
            ->firstOrFail();

        $this->checkLinkValidity($link);

        return response()->json([
            'status' => 'success',
            'data' => new CheckoutSessionResource($link),
        ], Response::HTTP_OK);
    }

    /**
     * Process the payment and return the provider's hosted checkout URL.
     */
    public function process(ProcessCheckoutRequest $request, string $token): JsonResponse
    {
        $link = PaymentLink::where('checkout_token', $token)->firstOrFaile();

        $this->checkLinkValidity($link);

        // Generate a unique reference for this specific transaction attempt
        $transactionReference = 'chk_' . Str::random(16);

        $transaction = Transaction::create([
            'merchant_id' => $link->merchant_id,
            'provider' => 'cray',
            'customer_email' => $request->validated('customer_email'),
            'reference' => $transactionReference,
            'amount' => $link->amount,
            'currency' => $link->currency,
            'status' => PaymentStatus::INITIALIZED,
            'metadata' => [
                'payment_link_id' => $link->id,
                'customer_name' => $request->validated('customer_name'),
            ],
        ]);

        $providerResponse = $this->paymentProvider->initializePayment($transaction);

        if (! $providerResponse['success']) {
            $transaction->update(['status' => PaymentStatus::FAILED]);
            return response()->json(['status' => 'error', 'message' => 'Payment initialization failed. Please try again.'], Response::HTTP_BAD_GATEWAY);
        }

        $transaction->update([
            'status' => PaymentStatus::PENDING,
            'provider_reference' => $providerResponse['provider_reference'],
            'checkout_url' => $providerResponse['checkout_url'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'checkout_url' => $transaction->checkout_url,
            ]
        ], Response::HTTP_OK);
    }

    private function checkLinkValidity(PaymentLink $link): void
    {
        if ($link->status !== PaymentLinkStatus::ACTIVE) {
            abort(Response::HTTP_BAD_REQUEST, 'This payment link is no longer active.');
        }

        if ($link->expiry && $link->expiry->isPast()) {
            $link->update(['status' => PaymentLinkStatus::EXPIRED]);
            abort(Response::HTTP_BAD_REQUEST, 'This payment link has expired.');
        }
    }
}
