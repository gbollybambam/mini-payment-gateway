<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Transaction;
use App\Models\Wallet;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $merchantCurrency = $merchant->currency ?? 'NGN';

        // FIX: Always ensure a wallet is returned, never 404.
        $wallet = Wallet::firstOrCreate(
            ['merchant_id' => $merchant->id, 'currency' => $merchantCurrency],
            ['available_balance' => 0, 'pending_balance' => 0]
        );

        $truePendingSum = Transaction::where('merchant_id', $merchant->id)
            ->where('currency', $wallet->currency)
            ->where('status', 'PENDING')
            ->sum('amount');

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $wallet->id,
                'currency' => $wallet->currency,
                'available_balance' => (float) $wallet->available_balance,
                'pending_balance' => (float) $truePendingSum,
                'updated_at' => $wallet->updated_at?->toIso8601String(),
            ]
        ], Response::HTTP_OK);
    }

    public function ledger(Request $request): JsonResponse
    {
        $merchant = $this->getMerchantContext($request);
        $wallet = $merchant->wallets()->firstOrFail();
        $perPage = $request->input('per_page', 20);

        $entries = $wallet->ledgerEntries()
            ->with('transaction:id,amount,fee,net_amount')
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => LedgerEntryResource::collection($entries),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ]
        ], Response::HTTP_OK);
    }
}
