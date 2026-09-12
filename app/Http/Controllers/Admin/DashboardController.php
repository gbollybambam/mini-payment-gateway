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
        // Aggregate platform-wide metrics
        $metrics = [
            'total_merchants' => Merchant::count(),
            'active_merchants' => Merchant::where('status', 'ACTIVE')->count(),

            'total_payment_volume' => Transaction::where('status', 'SUCCESS')->sum('amount'),
            'total_revenue_collected' => Transaction::where('status', 'SUCCESS')->sum('fee'), // Gateway's profit

            'total_withdrawals_volume' => Withdrawal::where('status', 'SUCCESS')->sum('amount'),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $metrics,
        ], Response::HTTP_OK);
    }
}
