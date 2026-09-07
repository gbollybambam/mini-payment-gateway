<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $merchant = $request->user();

        // Schema-ready aggregation structure matching Section 5.2 of the PRD
        // In Phases 2-5, these query models: Wallet, Transaction, Withdrawal
        $overview = [
            'currency' => $merchant->currency,
            'balances' => [
                'available_balance' => 0,
                'pending_balance' => 0,
            ],
            'metrics' => [
                'total_payment_volume' => 0,
                'transaction_count' => 0,
                'total_withdrawals_volume' => 0,
                'withdrawal_count' => 0,
            ],
            'recent_activity' => [],
        ];

        return response()->json([
            'status' => 'success',
            'data' => $overview,
        ], Response::HTTP_OK);
    }
}
