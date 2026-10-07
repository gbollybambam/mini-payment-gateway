<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PaymentProviders\ProviderFactory;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Resources\Payment\TransactionResource;
use App\Http\Requests\Payment\GetTransactionsRequest;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Requests\Payment\InitializePaymentRequest;
use Illuminate\Http\JsonResponse;
use App\Models\Wallet;
use App\Models\PaymentLink;
use App\Enums\PaymentLinkStatus;

class PaymentController extends Controller
{
    protected ProviderFactory $providerFactory;
    protected LedgerService $ledgerService;

    public function __construct(ProviderFactory $providerFactory, LedgerService $ledgerService)
    {
        $this->providerFactory = $providerFactory;
        $this->ledgerService = $ledgerService;
    }

    public function index(GetTransactionsRequest $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $search = $request->input('search');
        $status = $request->input('status');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        $query = Transaction::where('merchant_id', $merchant->id);

        if (!empty($search)) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('customer_email', 'ilike', "%{$search}%");

                if (\Illuminate\Support\Str::isUuid($search)) {
                    $q->orWhere('id', $search);
                }
                if (is_numeric($search)) {
                    $q->orWhere('amount', $search);
                }
            });
        }

        if (!empty($status)) {
            $query->where('status', $status);
        }

        if (!empty($startDate)) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if (!empty($endDate)) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $allowedSorts = ['created_at', 'amount'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'created_at';
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        $perPage = $request->input('per_page', 15);
        $transactions = $query->paginate($perPage);

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
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
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

        $providerName = $this->providerFactory->getActiveProviderName();
        $provider = $this->providerFactory->make($providerName);

        $transaction = Transaction::create([
            'merchant_id' => $merchant->id,
            'provider' => $providerName,
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

        $providerResponse = $provider->initializePayment($transaction);

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
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $transaction = Transaction::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($transaction->status !== PaymentStatus::PENDING) {
            return response()->json([
                'status' => 'success',
                'message' => "Transaction is already marked as {$transaction->status->value}.",
                'data' => $transaction,
            ], Response::HTTP_OK);
        }

        if (! $transaction->provider_reference) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction does not have an associated provider reference to verify.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $provider = $this->providerFactory->make($transaction->provider);

        try {
            $verificationResult = $provider->verifyPayment($transaction->provider_reference);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to verify payment with provider: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return DB::transaction(function () use ($transaction, $verificationResult, $merchant) {
            $lockedTransaction = Transaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTransaction->status !== PaymentStatus::PENDING) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Transaction was already updated by another process.',
                    'data' => $lockedTransaction,
                ], Response::HTTP_OK);
            }

            if ($verificationResult['success']) {
                $fee = $verificationResult['fee'] ?? 0;
                $netAmount = ($verificationResult['amount'] ?? $lockedTransaction->amount) - $fee;

                $lockedTransaction->update([
                    'status' => PaymentStatus::SUCCESS,
                    'fee' => $fee,
                    'net_amount' => $netAmount,
                ]);

                $wallet = Wallet::firstOrCreate(
                    ['merchant_id' => $lockedTransaction->merchant_id, 'currency' => $lockedTransaction->currency],
                    ['available_balance' => 0, 'pending_balance' => 0]
                );

                $this->ledgerService->credit(
                    wallet: $wallet,
                    amount: $lockedTransaction->net_amount,
                    type: \App\Enums\LedgerEntryType::PAYMENT,
                    reference: 'ldg_' . $lockedTransaction->id,
                    transaction: $lockedTransaction,
                    description: "Manual verification credit for {$lockedTransaction->reference}"
                );

                if (isset($lockedTransaction->metadata['payment_link_id'])) {
                    PaymentLink::where('id', $lockedTransaction->metadata['payment_link_id'])
                        ->update([
                            'status' => PaymentLinkStatus::COMPLETED,
                            'payment_id' => $lockedTransaction->id,
                        ]);
                }

                \App\Jobs\DispatchMerchantWebhook::dispatch(
                    $lockedTransaction->merchant,
                    'payment.success',
                    $lockedTransaction->toArray()
                );
            } else {
                if ($verificationResult['status'] === PaymentStatus::FAILED->value) {
                    $lockedTransaction->update(['status' => PaymentStatus::FAILED]);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction verification completed.',
                'data' => $lockedTransaction->fresh(),
            ], Response::HTTP_OK);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

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
