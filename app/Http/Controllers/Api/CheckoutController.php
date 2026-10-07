<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\ProcessCheckoutRequest;
use App\Http\Resources\Checkout\CheckoutSessionResource;
use App\Models\PaymentLink;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    public function __construct(
        private \App\Services\Ledger\LedgerService $ledgerService
    ) {}

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

    public function process(ProcessCheckoutRequest $request, string $token): JsonResponse
    {
        $link = PaymentLink::where('checkout_token', $token)->firstOrFail();
        $this->checkLinkValidity($link);

        $transactionReference = 'chk_' . Str::random(16);

        try {
            /** @var \App\Services\PaymentProviders\ProviderFactory $factory */
            $factory = app(\App\Services\PaymentProviders\ProviderFactory::class);
            $activeProviderName = $factory->getActiveProviderName();
            $paymentProvider = $factory->make($activeProviderName);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active payment provider available at this time. Please try again later.'
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $transaction = Transaction::create([
            'merchant_id' => $link->merchant_id,
            'provider' => $activeProviderName,
            'customer_email' => $request->validated('customer_email'),
            'reference' => $transactionReference,
            'amount' => $link->amount,
            'currency' => $link->currency,
            'status' => PaymentStatus::INITIALIZED,
            'metadata' => [
                'payment_link_id' => $link->id,
                'customer_name' => $request->validated('customer_name'),
                // THE FIX: Explicitly embed the reference into the callback URL
                'callback_url' => url("/api/checkout/{$token}/callback?reference={$transactionReference}"),
            ],
        ]);

        $providerResponse = $paymentProvider->initializePayment($transaction);

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

    // THE FIX: Inject Request to read the precise reference from the URL
    public function callback(Request $request, string $token): \Illuminate\Http\RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $link = PaymentLink::with(['merchant.settings'])->where('checkout_token', $token)->firstOrFail();

        // THE FIX: Grab the reference strictly from the URL (handles Paystack 'trxref' as well)
        $reference = $request->query('reference') ?? $request->query('trxref');

        $transactionQuery = Transaction::where('merchant_id', $link->merchant_id);

        if ($reference) {
            $transactionQuery->where('reference', $reference);
        } else {
            $transactionQuery->whereJsonContains('metadata->payment_link_id', $link->id)->latest();
        }

        $transaction = $transactionQuery->first();

        if (! $transaction) {
            return redirect()->away($link->merchant->settings?->cancel_url ?? "{$frontendUrl}/payment-failed");
        }

        // THE FIX: Fallback to local reference if provider_reference is missing
        $referenceToVerify = $transaction->provider_reference ?? $transaction->reference;

        if ($transaction->status === PaymentStatus::PENDING && $referenceToVerify) {
            try {
                /** @var \App\Services\PaymentProviders\ProviderFactory $factory */
                $factory = app(\App\Services\PaymentProviders\ProviderFactory::class);
                $paymentProvider = $factory->make($transaction->provider);
                $verificationResult = $paymentProvider->verifyPayment($referenceToVerify);
            } catch (\Exception $e) {
                return redirect()->away("{$frontendUrl}/payment-failed?reference={$transaction->reference}&error=verification_failed");
            }

            $newStatus = $verificationResult['success']
                ? PaymentStatus::from($verificationResult['status'])
                : PaymentStatus::FAILED;

            DB::transaction(function () use (&$transaction, $newStatus, $verificationResult, $link) {
                $lockedTransaction = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

                if ($lockedTransaction->status === PaymentStatus::PENDING) {
                    $lockedTransaction->update([
                        'status' => $newStatus,
                        'customer_email' => $lockedTransaction->customer_email ?: ($verificationResult['customer_email'] ?? null),
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
                $transaction = $lockedTransaction;
            });
        }

        if ($transaction->status === PaymentStatus::SUCCESS) {
            $successUrl = $link->redirect_url ?? $link->merchant->settings?->success_url ?? "{$frontendUrl}/payment-success";
            return redirect()->away("{$successUrl}?reference={$transaction->reference}");
        }

        $cancelUrl = $link->merchant->settings?->cancel_url ?? "{$frontendUrl}/payment-failed";
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
