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
        $query = $request->user()->withdrawals()->latest();
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
        $withdrawal = $request->user()->withdrawals()->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => new WithdrawalResource($withdrawal),
        ], Response::HTTP_OK);
    }

    public function initialize(InitializeWithdrawalRequest $request): JsonResponse
    {   
        $merchant = $request->user();
        $validated = $request->validated();

        $wallet = $merchant->wallets()->where('currency', $validated['currency'])->first();

        if (!$wallet) {
            return response()->json(['status' => 'error', 'message' => 'Wallet not found for this currency.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $withdrawal = DB::transaction(function () use ($merchant, $wallet, $validated) {
                // LedgerService->debit will THROW an exception if balance is insufficient
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
            // Catch insufficient balance or duplicate reference
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // 2. Hand off to Provider (Outside DB transaction)
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
            DB::transaction(function () use ($withdrawal, $wallet) {
                $withdrawal->update(['status' => WithdrawalStatus::FAILED]);

                $this->ledgerService->credit(
                    wallet: $wallet,
                    amount: $withdrawal->amount,
                    type: LedgerEntryType::REVERSAL, // Refund the money back
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

        // Dispatch Webhook
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
}
