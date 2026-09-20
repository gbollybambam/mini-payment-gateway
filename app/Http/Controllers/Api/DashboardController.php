<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\Withdrawal;

class DashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $merchant = $request->user() ?? $request->merchant;

        // Dynamically use the merchant's registered currency (fallback to NGN if missing)
        $merchantCurrency = $merchant->currency ?? 'NGN';

        $wallet = Wallet::firstOrCreate(
            ['merchant_id' => $merchant->id, 'currency' => $merchantCurrency],
            ['available_balance' => 0, 'pending_balance' => 0]
        );

        // Dynamically sum all currently pending transactions to get the true pending clearance amount
        $truePendingSum = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 'PENDING')
            ->sum('amount');

        $overview = [
            'currency' => $merchantCurrency,
            'balances' => [
                'available_balance' => $wallet ? (float) $wallet->available_balance : 0,
                'pending_balance' => (float) $truePendingSum,
            ],
            'metrics' => [
                'total_payment_volume' => Transaction::where('merchant_id', $merchant->id)
                    ->where('status', 'SUCCESS')
                    ->sum('net_amount'),
                'transaction_count' => Transaction::where('merchant_id', $merchant->id)->count(),
                'total_withdrawals_volume' => Withdrawal::where('merchant_id', $merchant->id)
                    ->where('status', 'SUCCESS')
                    ->sum('amount'),
                'withdrawal_count' => Withdrawal::where('merchant_id', $merchant->id)->count(),
            ],
            'recent_activity' => Transaction::where('merchant_id', $merchant->id)
                ->latest()
                ->take(5)
                ->get(['id', 'reference', 'amount', 'currency', 'status', 'created_at']),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $overview,
        ], Response::HTTP_OK);
    }
}
