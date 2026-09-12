<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{
    public function transactions(Request $request): JsonResponse
    {
        // Admins see everything, with optional filtering
        $query = Transaction::with('merchant:id,business_name')->latest();

        if ($request->has('status')) $query->where('status', $request->status);
        if ($request->has('merchant_id')) $query->where('merchant_id', $request->merchant_id);

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)], Response::HTTP_OK);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $query = Withdrawal::with('merchant:id,business_name')->latest();

        if ($request->has('status')) $query->where('status', $request->status);

        return response()->json(['status' => 'success', 'data' => $query->paginate(20)], Response::HTTP_OK);
    }
}
