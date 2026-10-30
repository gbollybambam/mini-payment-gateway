<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LedgerEntryType;
use App\Enums\WithdrawalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\GetWithdrawalsRequest;
use App\Http\Requests\Withdrawal\InitializeWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Merchant; // <--- Make sure Merchant model is imported
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\PaymentProviders\ProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WithdrawalController extends Controller
{
    public function __construct(
        private ProviderFactory $providerFactory,
        private LedgerService $ledgerService
    ) {}

    public function index(GetWithdrawalsRequest $request): JsonResponse
    {
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
        $merchant = $this->getMerchantContext($request);
        $withdrawal = $merchant->withdrawals()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new WithdrawalResource($withdrawal),
        ], Response::HTTP_OK);
    }

    /**
     * UI Dashboard Payout Initialization (Allows raw bank details from UI)
     */
    public function initialize(InitializeWithdrawalRequest $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $validated = $request->validated();

        $bankCode = $validated['bank_code'];
        $accountNumber = $validated['account_number'];
        $accountName = $validated['account_name'];

        return $this->processWithdrawal($merchant, $validated, $bankCode, $accountNumber, $accountName, null);
    }

    /**
     * B2B API Payout Initialization (Strictly enforces verification token)
     */
    public function initializeB2B(Request $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:100', 'decimal:0,2'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'reference' => ['required', 'string', 'max:100', 'unique:withdrawals,reference'],
            'verification_token' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $cacheKey = 'payout_verify_' . $validated['verification_token'];
        $cachedAccount = Cache::get($cacheKey);

        if (!$cachedAccount) {
            return response()->json([
                'status' => 'error',
                'message' => 'Account verification token is invalid or has expired. Please re-resolve the account.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bankCode = $cachedAccount['bank_code'];
        $accountNumber = $cachedAccount['account_number'];
        $accountName = $cachedAccount['account_name']; // Untamperable server-side data!

        return $this->processWithdrawal($merchant, $validated, $bankCode, $accountNumber, $accountName, $validated['verification_token']);
    }

    /**
     * Shared core logic for executing wallet debits and provider initialization
     * 
     * @param Merchant $merchant  <--- Type hint resolves IDE warning
     */
    private function processWithdrawal(Merchant $merchant, array $validated, string $bankCode, string $accountNumber, string $accountName, ?string $verificationToken): JsonResponse
    {
        $wallet = $merchant->wallets()->where('currency', $validated['currency'])->first();

        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'Wallet not found for this currency.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $withdrawal = DB::transaction(function () use ($merchant, $wallet, $validated, $bankCode, $accountNumber, $accountName) {
                $this->ledgerService->debit(
                    wallet: $wallet,
                    amount: $validated['amount'],
                    type: LedgerEntryType::WITHDRAWAL,
                    reference: 'ldg_wth_' . $validated['reference'],
                    transaction: null,
                    description: "Withdrawal to {$accountNumber}"
                );

                return Withdrawal::create([
                    'merchant_id' => $merchant->id,
                    'provider' => $this->providerFactory->getActiveProviderName(),
                    'reference' => $validated['reference'],
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'status' => WithdrawalStatus::PENDING,
                    'bank_account' => [
                        'bank_code' => $bankCode,
                        'account_number' => $accountNumber,
                        'account_name' => $accountName,
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
            $providerResponse = $this->providerFactory
                ->make($withdrawal->provider)
                ->initializePayout($withdrawal);

            if (
                !($providerResponse['success'] ?? false)
                || empty($providerResponse['provider_reference'])
                || !in_array(strtoupper((string) ($providerResponse['status'] ?? '')), ['PENDING', 'PROCESSING'], true)
                || !is_numeric($providerResponse['fee'] ?? 0)
                || (float) ($providerResponse['fee'] ?? 0) < 0
            ) {
                throw new \Exception('Provider rejected payout.');
            }

            $withdrawal->update([
                'status' => WithdrawalStatus::PROCESSING,
                'provider_reference' => $providerResponse['provider_reference'],
                'fee' => $providerResponse['fee'] ?? 0,
            ]);

            // Burn the token after successful usage so it cannot be re-used
            if ($verificationToken) {
                Cache::forget('payout_verify_' . $verificationToken);
            }
        } catch (\Throwable $e) {
            DB::transaction(function () use ($withdrawal, $wallet, $e) {
                $withdrawal->update([
                    'status' => WithdrawalStatus::FAILED,
                    'failure_reason' => 'Provider rejected payout'
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

        if (! $withdrawal->provider_reference) {
            return response()->json([
                'status' => 'error',
                'message' => 'Withdrawal has no provider reference.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $provider = $this->providerFactory->make($withdrawal->provider);
            $verificationResult = $provider->verifyPayout($withdrawal->provider_reference);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Withdrawal provider verification failed.', [
                'withdrawal_id' => $withdrawal->id,
                'exception' => get_class($e),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Provider verification failed.'], Response::HTTP_BAD_GATEWAY);
        }

        return DB::transaction(function () use ($withdrawal, $verificationResult, $merchant) {
            $locked = Withdrawal::where('id', $withdrawal->id)->lockForUpdate()->first();

            if (in_array($locked->status, [\App\Enums\WithdrawalStatus::SUCCESS, \App\Enums\WithdrawalStatus::FAILED, \App\Enums\WithdrawalStatus::CANCELLED])) {
                return response()->json(['status' => 'success', 'data' => new WithdrawalResource($locked)]);
            }

            $providerStatus = strtoupper((string) ($verificationResult['status'] ?? ''));

            if ($providerStatus === 'SUCCESS' || $providerStatus === 'SUCCESSFUL') {
                $locked->update(['status' => \App\Enums\WithdrawalStatus::SUCCESS]);
                \App\Jobs\DispatchMerchantWebhook::dispatch($merchant, 'withdrawal.success', $locked->toArray());
            } elseif (in_array($providerStatus, ['FAILED', 'REVERSED', 'REJECTED'], true)) {
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

            // Generate a transient verification token (valid for 15 minutes)
            $verificationToken = 'vtok_' . Str::random(32);
            $cacheKey = 'payout_verify_' . $verificationToken;

            $accountName = $result['account_name'] ?? $result['data']['account_name'] ?? 'Verified Account';

            Cache::put($cacheKey, [
                'account_number' => $request->input('account_number'),
                'bank_code' => $request->input('bank_code'),
                'account_name' => $accountName,
            ], now()->addMinutes(15));

            return response()->json([
                'status' => 'success',
                'data' => array_merge($result, [
                    'verification_token' => $verificationToken,
                ])
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
