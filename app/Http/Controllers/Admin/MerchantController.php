<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Merchant::latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $merchants = $query->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $merchants->items(),
            'meta' => [
                'current_page' => $merchants->currentPage(),
                'last_page' => $merchants->lastPage(),
                'total' => $merchants->total(),
            ]
        ], Response::HTTP_OK);
    }

    public function show(string $id): JsonResponse
    {
        $merchant = Merchant::with(['wallets', 'settings'])->findOrFail($id);

        // PRD Section 17: Inspect financial activity
        $financialSummary = [
            'total_transactions' => $merchant->transactions()->count(),
            'successful_payment_volume' => (float) $merchant->transactions()->where('status', 'SUCCESS')->sum('amount'),
            'total_withdrawn' => (float) $merchant->withdrawals()->where('status', 'SUCCESS')->sum('amount'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'merchant' => $merchant,
                'financial_summary' => $financialSummary,
            ],
        ], Response::HTTP_OK);
    }

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $merchant = Merchant::findOrFail($id);

        $newStatus = $merchant->status === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE';
        $merchant->update(['status' => $newStatus]);

        \App\Models\AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'TOGGLE_MERCHANT_STATUS',
            'target_type' => Merchant::class,
            'target_id' => $merchant->id,
            'changes' => ['status' => $newStatus],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Merchant status updated to {$newStatus}.",
            'data' => [
                'id' => $merchant->id,
                'status' => $merchant->status,
            ]
        ], Response::HTTP_OK);
    }

    public function adjustBalance(Request $request, string $id, \App\Services\Ledger\LedgerService $ledgerService): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'direction' => 'required|in:CREDIT,DEBIT',
            'description' => 'required|string|max:255',
            'currency' => 'required|string|size:3'
        ]);

        $merchant = Merchant::findOrFail($id);
        $wallet = $merchant->wallets()->where('currency', $validated['currency'])->firstOrFail();

        $entry = \Illuminate\Support\Facades\DB::transaction(function () use ($ledgerService, $wallet, $validated, $request, $merchant) {
            $method = $validated['direction'] === 'CREDIT' ? 'credit' : 'debit';

            $ledgerEntry = $ledgerService->$method(
                wallet: $wallet,
                amount: $validated['amount'],
                type: \App\Enums\LedgerEntryType::ADJUSTMENT,
                reference: 'adj_' . \Illuminate\Support\Str::random(16),
                transaction: null,
                description: $validated['description']
            );

            \App\Models\AuditLog::create([
                'admin_id' => $request->user()->id,
                'action' => 'MANUAL_LEDGER_ADJUSTMENT',
                'target_type' => Merchant::class,
                'target_id' => $merchant->id,
                'changes' => $validated,
                'ip_address' => $request->ip(),
            ]);

            return $ledgerEntry;
        });

        return response()->json(['status' => 'success', 'data' => $entry]);
    }
}
