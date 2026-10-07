<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // FIX: Use global context resolver
        $merchantId = $this->getMerchantContext($request)->id;

        $query = Transaction::where('merchant_id', $merchantId);

        // 1. Search (Reference, Email, or Exact Amount)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                // Use ilike for case-insensitive searching in PostgreSQL
                $q->where('reference', 'ilike', "%{$search}%")
                    ->orWhere('customer_email', 'ilike', "%{$search}%");

                // Only query the numeric column if the search term is actually a number
                if (is_numeric($search)) {
                    $q->orWhere('amount', clone $search);
                }
            });
        }

        // 2. Exact Filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($startDate = $request->input('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // 3. Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowedSorts = ['created_at', 'amount'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        // 4. Paginate
        $perPage = (int) $request->input('per_page', 15);
        $transactions = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ]
        ], Response::HTTP_OK);
    }
}
