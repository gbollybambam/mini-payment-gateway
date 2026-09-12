<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallets()->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $wallet->id,
                'currency' => $wallet->currency,
                'available_balance' => (float) $wallet->available_balance,
                'pending_balance' => (float) $wallet->pending_balance,
                'updated_at' => $wallet->updated_at?->toIso8601String(),
            ]
        ], Response::HTTP_OK);
    }

    public function ledger(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallets()->firstOrFail();

        $entries = $wallet->ledgerEntries()->latest()->paginate(20);

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
