<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Payment\TransactionResource;
use App\Http\Requests\Payment\GetTransactionsRequest;
use Illuminate\Database\Eloquent\Builder;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\InitializePaymentRequest;
use App\Models\Transaction;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


class PaymentController extends Controller
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider
    ) {}

    public function index(GetTransactionsRequest $request): JsonResponse
    {
        $merchant = $request->merchant;
        $validated = $request->validated();

        $query = Transaction::where('merchant_id', $merchant->id);

        if (!empty($validated['search'])) {
            $searchTerm = $validated['search'];
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('id', $searchTerm)
                    ->orWhere('reference', 'ilike', "%{$searchTerm}%")
                    ->orWhere('customer_email', 'ilike', "%{$searchTerm}%");
            });
        }

        if (!empty($validated['start_date'])) {
            $query->whereDate('created_at', '>=', $validated['start_date']);
        }

        if (!empty($validated['end_date'])) {
            $query->whereDate('created_at', '<=', $validated['end_date']);
        }

        $exactFilters = ['status', 'amount', 'currency', 'provider', 'reference', 'customer_email'];
        foreach ($exactFilters as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        // Always return paginated results for ledger/history endpoints
        $perPage = $validated['per_page'] ?? 15;
        $transactions = $query->latest()->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => TransactionResource::collection($transactions),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ]
        ], Response::HTTP_OK);
    }

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

        \App\Jobs\DispatchMerchantWebhook::dispatch(
            $transaction->merchant,
            'payment.pending',
            $transaction->toArray()
        );

        return $this->paymentResponse($transaction, Response::HTTP_CREATED);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        $merchant = $request->merchant;

        $transaction = Transaction::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->firstOrFail();

        if (in_array($transaction->status, [PaymentStatus::SUCCESS, PaymentStatus::FAILED])) {
            return $this->paymentResponse($transaction, Response::HTTP_OK);
        }

        if (! $transaction->provider_reference) {
            return response()->json([
                'status' => 'error',
                'message' => 'No provider reference exists for this transaction.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $verificationResult = $this->paymentProvider->verifyPayment($transaction->provider_reference);

        if (! $verificationResult['success']) {
            $transaction->update(['status' => PaymentStatus::FAILED]);

            \App\Jobs\DispatchMerchantWebhook::dispatch(
                $transaction->merchant,
                'payment.failed',
                $transaction->toArray()
            );

            return $this->paymentResponse($transaction, Response::HTTP_OK);
        }

        $newStatus = PaymentStatus::from($verificationResult['status']);

        $transaction->update([
            'status' => PaymentStatus::from($verificationResult['status']),
            'fee' => $verificationResult['fee'] ?? 0,
            'net_amount' => $verificationResult['amount'] - ($verificationResult['fee'] ?? 0),
        ]);

        // Dispatch webhooks based on the newly verified state
        if ($newStatus === PaymentStatus::SUCCESS) {
            \App\Jobs\DispatchMerchantWebhook::dispatch(
                $transaction->merchant,
                'payment.success',
                $transaction->toArray()
            );
        } elseif ($newStatus === PaymentStatus::FAILED) {
            \App\Jobs\DispatchMerchantWebhook::dispatch(
                $transaction->merchant,
                'payment.failed',
                $transaction->toArray()
            );
        }

        return $this->paymentResponse($transaction, Response::HTTP_OK);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $merchant = $request->merchant;

        $transaction = Transaction::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => new TransactionResource($transaction)
        ], Response::HTTP_OK);
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
