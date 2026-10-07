<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LedgerEntryType;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\GetWithdrawalsRequest;
use App\Http\Requests\Withdrawal\InitializeWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\PaymentProviders\Contracts\PaymentProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WithdrawalController extends Controller
{
    public function __construct(
        private PaymentProviderInterface $paymentProvider,
        private LedgerService $ledgerService
    ) {}

    public function index(GetWithdrawalsRequest $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $query = $merchant->withdrawals()->latest();

        $validated = $request->validated();

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['start_date'])) {
            $query->whereDate('created_at', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('created_at', '<=', $validated['end_date']);
        }

        $withdrawals = $query->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => WithdrawalResource::collection($withdrawals),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
            ]
        ], Response::HTTP_OK);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $withdrawal = $merchant->withdrawals()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new WithdrawalResource($withdrawal),
        ], Response::HTTP_OK);
    }

    public function initialize(InitializeWithdrawalRequest $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);
        $validated = $request->validated();

        $wallet = $merchant->wallets()->where('currency', $validated['currency'])->first();

        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'Wallet not found for this currency.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $withdrawal = DB::transaction(function () use ($merchant, $wallet, $validated) {
                $this->ledgerService->debit(
                    wallet: $wallet,
                    amount: $validated['amount'],
                    type: LedgerEntryType::WITHDRAWAL,
                    reference: 'ldg_wth_' . $validated['reference'],
                    transaction: null,
                    description: "Withdrawal to {$validated['account_number']}"
                );

                return Withdrawal::create([
                    'merchant_id' => $merchant->id,
                    'reference' => $validated['reference'],
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'status' => WithdrawalStatus::PENDING,
                    'bank_account' => [
                        'bank_code' => $validated['bank_code'],
                        'account_number' => $validated['account_number'],
                        'account_name' => $validated['account_name'],
                    ],
                    'metadata' => $validated['metadata'] ?? [],
                ]);
            });
        } catch (\Exception $e) {
            DB::rollBack();
            $reason = $e->getMessage() ?: 'Payout provider rejected transaction';

            return response()->json([
                'status' => 'error',
                'message' => "Payout failed: {$reason}. Funds have been refunded to your wallet balance.",
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $providerResponse = $this->paymentProvider->initializePayout($withdrawal);

            if (!$providerResponse['success']) {
                throw new \Exception('Provider rejected payout.');
            }

            $withdrawal->update([
                'status' => WithdrawalStatus::PROCESSING,
                'provider_reference' => $providerResponse['provider_reference'],
                'fee' => $providerResponse['fee'] ?? 0,
            ]);
        } catch (\Exception $e) {
            DB::transaction(function () use ($withdrawal, $wallet, $e) {
                $withdrawal->update([
                    'status' => WithdrawalStatus::FAILED,
                    'failure_reason' => substr($e->getMessage(), 0, 255)
                ]);

                $this->ledgerService->credit(
                    wallet: $wallet,
                    amount: $withdrawal->amount,
                    type: LedgerEntryType::REVERSAL,
                    reference: 'ldg_rev_' . $withdrawal->reference,
                    transaction: null,
                    description: "Reversal for failed withdrawal: {$withdrawal->reference}"
                );
            });

            return response()->json([
                'status' => 'error',
                'message' => 'Payout initialization failed. Funds have been reversed to your wallet.'
            ], Response::HTTP_BAD_GATEWAY);
        }

        \App\Jobs\DispatchMerchantWebhook::dispatch(
            $merchant,
            'withdrawal.pending',
            $withdrawal->toArray()
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Withdrawal initiated successfully.',
            'data' => new WithdrawalResource($withdrawal),
        ], Response::HTTP_CREATED);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        // FIX: Use global context resolver
        $merchant = $this->getMerchantContext($request);

        $withdrawal = Withdrawal::where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->firstOrFail();

        if (in_array($withdrawal->status, [\App\Enums\WithdrawalStatus::SUCCESS, \App\Enums\WithdrawalStatus::FAILED, \App\Enums\WithdrawalStatus::CANCELLED])) {
            return response()->json([
                'status' => 'success',
                'message' => "Withdrawal is already {$withdrawal->status->value}.",
                'data' => new WithdrawalResource($withdrawal)
            ], Response::HTTP_OK);
        }

        try {
            $verificationResult = $this->paymentProvider->verifyPayout($withdrawal->provider_reference ?? $withdrawal->reference);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Provider verification failed: ' . $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return DB::transaction(function () use ($withdrawal, $verificationResult, $merchant) {
            $locked = Withdrawal::where('id', $withdrawal->id)->lockForUpdate()->first();

            if (in_array($locked->status, [\App\Enums\WithdrawalStatus::SUCCESS, \App\Enums\WithdrawalStatus::FAILED])) {
                return response()->json(['status' => 'success', 'data' => new WithdrawalResource($locked)]);
            }

            if (str_contains(strtoupper($verificationResult['status']), 'SUCCESS')) {
                $locked->update(['status' => \App\Enums\WithdrawalStatus::SUCCESS]);
                \App\Jobs\DispatchMerchantWebhook::dispatch($merchant, 'withdrawal.success', $locked->toArray());
            } elseif (in_array(strtoupper($verificationResult['status']), ['FAILED', 'REVERSED', 'REJECTED'])) {
                $locked->update([
                    'status' => \App\Enums\WithdrawalStatus::FAILED,
                    'failure_reason' => $verificationResult['reason'] ?? 'Bank rejected transfer'
                ]);

                $wallet = $merchant->wallets()->where('currency', $locked->currency)->first();
                if ($wallet) {
                    $this->ledgerService->credit(
                        wallet: $wallet,
                        amount: $locked->amount,
                        type: \App\Enums\LedgerEntryType::REVERSAL,
                        reference: 'ldg_rev_' . $locked->reference,
                        transaction: null,
                        description: "Reversal for failed payout: {$locked->reference}"
                    );
                }

                \App\Jobs\DispatchMerchantWebhook::dispatch($merchant, 'withdrawal.failed', $locked->toArray());
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Withdrawal status synced.',
                'data' => new WithdrawalResource($locked->fresh())
            ], Response::HTTP_OK);
        });
    }

    public function getBanks(Request $request): JsonResponse
    {
        $currency = $request->query('currency', 'NGN');

        try {
            /** @var \App\Services\PaymentProviders\ProviderFactory $factory */
            $factory = app(\App\Services\PaymentProviders\ProviderFactory::class);
            $activeProviderName = $factory->getActiveProviderName();
            $paymentProvider = $factory->make($activeProviderName);

            $banks = $paymentProvider->getBanks($currency);

            return response()->json([
                'status' => 'success',
                'data' => $banks
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    public function resolveAccount(Request $request): JsonResponse
    {
        $request->validate([
            'account_number' => 'required|string',
            'bank_code' => 'required|string',
        ]);

        try {
            /** @var \App\Services\PaymentProviders\ProviderFactory $factory */
            $factory = app(\App\Services\PaymentProviders\ProviderFactory::class);
            $activeProviderName = $factory->getActiveProviderName();
            $paymentProvider = $factory->make($activeProviderName);

            $result = $paymentProvider->resolveAccount(
                $request->input('account_number'),
                $request->input('bank_code')
            );

            return response()->json([
                'status' => 'success',
                'data' => $result
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
