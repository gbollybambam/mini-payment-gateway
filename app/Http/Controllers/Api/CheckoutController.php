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
use Illuminate\Support\Facades\DB;


class CheckoutController extends Controller
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider,
        private \App\Services\Ledger\LedgerService $ledgerService
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
        $link = PaymentLink::where('checkout_token', $token)->firstOrFail();

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
                'callback_url' => url("/api/checkout/{$token}/callback"),
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

    /**
     * Handle the customer returning from the provider's hosted checkout.
     */
    public function callback(string $token): \Illuminate\Http\RedirectResponse
    {
        $link = PaymentLink::with(['merchant.settings'])->where('checkout_token', $token)->firstOrFail();

        // Get the most recent transaction attempt for this link
        $transaction = Transaction::where('merchant_id', $link->merchant_id)
            ->whereJsonContains('metadata->payment_link_id', $link->id)
            ->latest()
            ->first();

        if (! $transaction) {
            return redirect()->away($link->merchant->settings?->cancel_url ?? 'https://pay.example.com/payment-failed');
        }

        // If it's still pending, aggressively verify it now that the customer has returned
        if ($transaction->status === PaymentStatus::PENDING && $transaction->provider_reference) {
            $verificationResult = $this->paymentProvider->verifyPayment($transaction->provider_reference);

            $newStatus = $verificationResult['success']
                ? PaymentStatus::from($verificationResult['status'])
                : PaymentStatus::FAILED;

            DB::transaction(function () use (&$transaction, $newStatus, $verificationResult, $link) {
                $lockedTransaction = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

                if ($lockedTransaction->status === PaymentStatus::PENDING) {
                    $lockedTransaction->update([
                        'status' => $newStatus,
                        'fee' => $verificationResult['fee'] ?? 0,
                        'net_amount' => ($verificationResult['amount'] ?? $lockedTransaction->amount) - ($verificationResult['fee'] ?? 0),
                    ]);

                    \App\Jobs\DispatchMerchantWebhook::dispatch(
                        $lockedTransaction->merchant,
                        $newStatus === PaymentStatus::SUCCESS ? 'payment.success' : 'payment.failed',
                        $lockedTransaction->toArray()
                    );

                    if ($newStatus === PaymentStatus::SUCCESS) {
                        try {
                            $wallet = \App\Models\Wallet::firstOrCreate(
                                ['merchant_id' => $lockedTransaction->merchant_id, 'currency' => $lockedTransaction->currency],
                                ['available_balance' => 0, 'pending_balance' => 0]
                            );

                            $this->ledgerService->credit(
                                wallet: $wallet,
                                amount: $lockedTransaction->net_amount,
                                type: \App\Enums\LedgerEntryType::PAYMENT,
                                reference: 'ldg_' . $lockedTransaction->id,
                                transaction: $lockedTransaction,
                                description: "Payment credit for {$lockedTransaction->reference}"
                            );
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Ledger credit failed: ' . $e->getMessage());
                        }

                        $link->update([
                            'status' => PaymentLinkStatus::COMPLETED,
                            'payment_id' => $lockedTransaction->id,
                        ]);
                    }
                }

                // Sync our outer variable so the redirect logic below knows the final status
                $transaction = $lockedTransaction;
            });
        }

        // Determine where to redirect the customer based on the final status
        if ($transaction->status === PaymentStatus::SUCCESS) {
            // PRD Section 8 & 16: Use link specific redirect, fallback to merchant global success URL
            $successUrl = $link->redirect_url ?? $link->merchant->settings?->success_url ?? 'https://pay.example.com/payment-success';

            // Append the transaction reference so the merchant's frontend can display a receipt
            return redirect()->away("{$successUrl}?reference={$transaction->reference}");
        }

        // Handle Failure/Cancellation
        $cancelUrl = $link->merchant->settings?->cancel_url ?? 'https://pay.example.com/payment-failed';
        return redirect()->away("{$cancelUrl}?reference={$transaction->reference}");
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
