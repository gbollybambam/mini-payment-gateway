<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use App\Services\PaymentProviders\ProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private \App\Services\Ledger\LedgerService $ledgerService
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $allowedProviders = ['cray', 'paystack'];

        if (!in_array(strtolower($provider), $allowedProviders)) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported provider.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $paymentProvider = $this->providerFactory->make($provider);
            $verifiedData = $paymentProvider->processWebhook($request);
        } catch (\Throwable $e) {
            Log::warning("Webhook processing error from provider: {$provider}", ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Processing error.'], Response::HTTP_UNAUTHORIZED);
        }

        $event = $verifiedData['event'] ?? '';
        $knownEvents = ['payment.success', 'payment.failed', 'withdrawal.success', 'withdrawal.failed'];

        if (! ($verifiedData['is_valid'] ?? false)
            || ! in_array($event, $knownEvents, true)
            || empty($verifiedData['provider_reference'])) {
            return response()->json(['status' => 'error', 'message' => 'Webhook validation failed.'], Response::HTTP_UNAUTHORIZED);
        }

        // ---------------------------------------------------------
        // 1. PAYMENT WEBHOOKS
        // ---------------------------------------------------------
        if (str_starts_with($event, 'payment.')) {
            return DB::transaction(function () use ($verifiedData, $event) {

                // THE FIX: Check BOTH provider_reference AND our local reference!
                $transaction = Transaction::where(function ($query) use ($verifiedData) {
                    $query->where('provider_reference', $verifiedData['provider_reference'])
                        ->orWhere('reference', $verifiedData['provider_reference']);
                })
                    ->lockForUpdate()
                    ->first();

                if (! $transaction) {
                    Log::info("Webhook received for unknown transaction.", ['reference' => $verifiedData['provider_reference']]);
                    return response()->json(['status' => 'success'], Response::HTTP_OK);
                }

                if (in_array($transaction->status, [PaymentStatus::SUCCESS, PaymentStatus::FAILED])) {
                    return response()->json(['status' => 'success', 'message' => 'Transaction already processed.'], Response::HTTP_OK);
                }

                if ($event === 'payment.success') {
                    $fee = $verifiedData['fee'] ?? 0;
                    $providerAmount = $verifiedData['amount'] ?? null;
                    $providerCurrency = $verifiedData['currency'] ?? null;
                    $providerReference = $verifiedData['provider_reference'] ?? null;

                    if ($providerAmount === null
                        || number_format((float) $providerAmount, 4, '.', '') !== number_format((float) $transaction->amount, 4, '.', '')
                        || ($providerCurrency !== null && strtoupper((string) $providerCurrency) !== strtoupper((string) $transaction->currency))
                        || ($providerReference !== null
                            && (string) $providerReference !== (string) $transaction->provider_reference
                            && (string) $providerReference !== (string) $transaction->reference)
                        || ! is_numeric($fee)
                        || (float) $fee < 0
                        || (float) $fee > (float) $transaction->amount) {
                        Log::warning('Webhook payment details did not match local transaction.', [
                            'transaction_id' => $transaction->id,
                        ]);
                        return response()->json(['status' => 'error', 'message' => 'Payment details mismatch.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }

                    $netAmount = (float) $providerAmount - (float) $fee;

                    $transaction->update([
                        'status' => PaymentStatus::SUCCESS,
                        'fee' => $fee,
                        'net_amount' => $netAmount,
                    ]);

                    $wallet = \App\Models\Wallet::firstOrCreate(
                        ['merchant_id' => $transaction->merchant_id, 'currency' => $transaction->currency],
                        ['available_balance' => 0, 'pending_balance' => 0]
                    );

                    if ($wallet) {
                        $this->ledgerService->credit(
                            wallet: $wallet,
                            amount: $transaction->net_amount,
                            type: \App\Enums\LedgerEntryType::PAYMENT,
                            reference: 'ldg_' . $transaction->id,
                            transaction: $transaction,
                            description: "Payment credit for {$transaction->reference}"
                        );
                    }

                    if (isset($transaction->metadata['payment_link_id'])) {
                        $link = \App\Models\PaymentLink::where('id', $transaction->metadata['payment_link_id'])
                            ->where('merchant_id', $transaction->merchant_id)
                            ->where(function ($query) use ($transaction) {
                                $query->whereNull('payment_id')
                                    ->orWhere('payment_id', $transaction->id);
                            })
                            ->first();
                        if ($link) {
                            $link->update([
                                'status' => \App\Enums\PaymentLinkStatus::COMPLETED,
                                'payment_id' => $transaction->id,
                            ]);
                        }
                    }

                    \App\Jobs\DispatchMerchantWebhook::dispatch(
                        $transaction->merchant,
                        'payment.success',
                        $transaction->toArray()
                    );
                } elseif ($event === 'payment.failed') {
                    $transaction->update(['status' => PaymentStatus::FAILED]);

                    \App\Jobs\DispatchMerchantWebhook::dispatch(
                        $transaction->merchant,
                        'payment.failed',
                        $transaction->toArray()
                    );
                }

                return response()->json(['status' => 'success'], Response::HTTP_OK);
            });
        }

        // ---------------------------------------------------------
        // 2. WITHDRAWAL WEBHOOKS
        // ---------------------------------------------------------
        if (str_starts_with($event, 'withdrawal.')) {
            return DB::transaction(function () use ($verifiedData, $event) {
                $withdrawal = \App\Models\Withdrawal::where('provider_reference', $verifiedData['provider_reference'])
                    ->orWhere('reference', $verifiedData['provider_reference'])
                    ->lockForUpdate()
                    ->first();

                if (! $withdrawal) {
                    Log::info("Webhook received for unknown withdrawal.", ['reference' => $verifiedData['provider_reference']]);
                    return response()->json(['status' => 'success'], Response::HTTP_OK);
                }

                if (in_array($withdrawal->status, [\App\Enums\WithdrawalStatus::SUCCESS, \App\Enums\WithdrawalStatus::FAILED])) {
                    return response()->json(['status' => 'success', 'message' => 'Withdrawal already processed.'], Response::HTTP_OK);
                }

                $webhookAmount = $verifiedData['amount'] ?? null;
                $webhookCurrency = $verifiedData['currency'] ?? null;
                if ($webhookAmount === null
                    || $webhookCurrency === null
                    || number_format((float) $webhookAmount, 4, '.', '') !== number_format((float) $withdrawal->amount, 4, '.', '')
                    || strtoupper((string) $webhookCurrency) !== strtoupper((string) $withdrawal->currency)) {
                    Log::warning('Webhook withdrawal details did not match local withdrawal.', [
                        'withdrawal_id' => $withdrawal->id,
                    ]);
                    return response()->json(['status' => 'error', 'message' => 'Withdrawal details mismatch.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                if ($event === 'withdrawal.success') {
                    $withdrawal->update(['status' => \App\Enums\WithdrawalStatus::SUCCESS]);

                    \App\Jobs\DispatchMerchantWebhook::dispatch(
                        $withdrawal->merchant,
                        'withdrawal.success',
                        $withdrawal->toArray()
                    );
                } elseif ($event === 'withdrawal.failed') {
                    $withdrawal->update([
                        'status' => \App\Enums\WithdrawalStatus::FAILED,
                        'failure_reason' => $verifiedData['reason'] ?? 'Bank network rejected transfer',
                    ]);

                    $wallet = \App\Models\Wallet::where('merchant_id', $withdrawal->merchant_id)
                        ->where('currency', $withdrawal->currency)
                        ->first();

                    if ($wallet) {
                        $this->ledgerService->credit(
                            wallet: $wallet,
                            amount: $withdrawal->amount,
                            type: \App\Enums\LedgerEntryType::REVERSAL,
                            reference: 'ldg_rev_' . $withdrawal->id,
                            transaction: null,
                            description: "Reversal for failed bank withdrawal: {$withdrawal->reference}"
                        );
                    }

                    \App\Jobs\DispatchMerchantWebhook::dispatch(
                        $withdrawal->merchant,
                        'withdrawal.failed',
                        $withdrawal->toArray()
                    );
                }

                return response()->json(['status' => 'success'], Response::HTTP_OK);
            });
        }

        return response()->json(['status' => 'success'], Response::HTTP_OK);
    }
}
