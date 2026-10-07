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
        // THE FIX: Automatically resolves whether this is an Owner, a Team Member, or an API Key.
        $merchant = $this->getMerchantContext($request);

        $merchantCurrency = $merchant->currency ?? 'NGN';

        $wallet = Wallet::firstOrCreate(
            ['merchant_id' => $merchant->id, 'currency' => $merchantCurrency],
            ['available_balance' => 0, 'pending_balance' => 0]
        );

        $txStats = Transaction::where('merchant_id', $merchant->id)
            ->selectRaw("
                COUNT(id) as total_count,
                COALESCE(SUM(CASE WHEN status = 'PENDING' THEN amount ELSE 0 END), 0) as pending_sum,
                COALESCE(SUM(CASE WHEN status = 'SUCCESS' THEN net_amount ELSE 0 END), 0) as success_sum
            ")->first();

        $wdStats = Withdrawal::where('merchant_id', $merchant->id)
            ->selectRaw("
                COUNT(id) as total_count,
                COALESCE(SUM(CASE WHEN status = 'SUCCESS' THEN amount ELSE 0 END), 0) as success_sum
            ")->first();

        $overview = [
            'currency' => $merchantCurrency,
            'balances' => [
                'available_balance' => $wallet ? (float) $wallet->available_balance : 0,
                'pending_balance' => (float) ($txStats->pending_sum ?? 0),
            ],
            'metrics' => [
                'total_payment_volume' => (float) ($txStats->success_sum ?? 0),
                'transaction_count' => (int) ($txStats->total_count ?? 0),
                'total_withdrawals_volume' => (float) ($wdStats->success_sum ?? 0),
                'withdrawal_count' => (int) ($wdStats->total_count ?? 0),
            ],
            'recent_activity' => Transaction::where('merchant_id', $merchant->id)
                ->latest()
                ->take(5)
                ->get(['id', 'reference', 'amount', 'currency', 'status', 'created_at', 'payment_method', 'provider']),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $overview,
        ], Response::HTTP_OK);
    }
}
