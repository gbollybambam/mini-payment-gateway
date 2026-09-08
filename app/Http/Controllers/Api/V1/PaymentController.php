<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Models\Transaction;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider
    ) {}

    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $merchant = $request->merchant;
        $idempotencyKey = $request->header('Idempotency-Key');


        if ($idempotencyKey) {
            $existingTransaction = Transaction::where('merchant_id', $merchant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingTransaction) {
                if ($existingTransaction->status === PaymentStatus::FAILED) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'A previous attempt with this idempotency key failed. Please generate a new key and try again.',
                        'data' => [
                            'payment_id' => $existingTransaction->id,
                            'status' => $existingTransaction->status->value,
                        ]
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return $this->paymentResponse($existingTransaction, Response::HTTP_OK);
            }
        }

        $transaction = Transaction::create([
            'merchant_id' => $merchant->id,
            'provider' => 'cray',
            'customer_email' => $request->validated('customer_email'),
            'reference' => $request->validated('reference'),
            'amount' => $request->validated('amount'),
            'currency' => $request->validated('currency'),
            'status' => PaymentStatus::INITIALIZED,
            'metadata' => array_merge(
                $request->validated('metadata') ?? [],
                ['callback_url' => $request->validated('callback_url')]
            ),
            'idempotency_key' => $idempotencyKey,
        ]);

        $providerResponse = $this->paymentProvider->initializePayment($transaction);

        if (! $providerResponse['success']) {
            $transaction->update(['status' => PaymentStatus::FAILED]);
            return response()->json(['status' => 'error', 'message' => 'Provider initialization failed.'], Response::HTTP_BAD_GATEWAY);
        }

        $transaction->update([
            'status' => PaymentStatus::PENDING,
            'provider_reference' => $providerResponse['provider_reference'],
            'checkout_url' => $providerResponse['checkout_url'],
        ]);

        return $this->paymentResponse($transaction, Response::HTTP_CREATED);
    }

    private function paymentResponse(Transaction $transaction, int $statusCode): JsonResponse
    {
        $isError = in_array($transaction->status, [PaymentStatus::FAILED, PaymentStatus::CANCELLED]);

        return response()->json([
            'status' => $isError ? 'error' : 'success',
            'data' => [
                'payment_id' => $transaction->id,
                'reference' => $transaction->reference,
                'status' => $transaction->status->value,
                'checkout_url' => $transaction->checkout_url,
            ]
        ], $statusCode);
    }
}
