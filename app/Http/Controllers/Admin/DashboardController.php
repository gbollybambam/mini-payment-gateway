<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        // 1. Aggregate platform-wide metrics
        $metrics = [
            'total_merchants' => Merchant::count(),
            'active_merchants' => Merchant::where('status', 'ACTIVE')->count(),
            'total_payment_volume' => Transaction::where('status', 'SUCCESS')->sum('amount'),
            'total_revenue_collected' => Transaction::where('status', 'SUCCESS')->sum('fee'),
            'total_withdrawals_volume' => Withdrawal::where('status', 'SUCCESS')->sum('amount'),
        ];

        // 2. Fetch actionable items (Withdrawals needing approval)
        // Note: The PRD states withdrawal statuses include REQUESTED and PROCESSING
        $action_required = Withdrawal::whereIn('status', ['REQUESTED', 'PENDING', 'PROCESSING'])
            ->latest()
            ->take(5)
            ->get();

        // 3. Fetch live ledger pulse
        $recent_transactions = Transaction::latest()
            ->take(6)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'metrics' => $metrics,
                'action_required' => $action_required,
                'recent_transactions' => $recent_transactions,
            ]
        ], Response::HTTP_OK);
    }
}
