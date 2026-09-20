<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Transaction;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user() ?? $request->merchant;
        $wallet = $merchant->wallets()->firstOrFail();

        // Calculate true pending balance dynamically for this wallet's currency
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
                'pending_balance' => (float) $truePendingSum, // Use the dynamic sum
                'updated_at' => $wallet->updated_at?->toIso8601String(),
            ]
        ], Response::HTTP_OK);
    }

    public function ledger(Request $request): JsonResponse
    {
        $merchant = $request->user() ?? $request->merchant;
        $wallet = $merchant->wallets()->firstOrFail();

        // Ensure pagination is dynamic based on frontend request
        $perPage = $request->input('per_page', 20);
        $entries = $wallet->ledgerEntries()->latest()->paginate($perPage);

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
