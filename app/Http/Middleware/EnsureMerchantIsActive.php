<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Get the merchant depending on whether they logged in via Dashboard (Sanctum) or API Key
        $merchant = $request->user() ?? $request->merchant;

        if ($merchant && $merchant->status === 'SUSPENDED') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your account has been suspended. Please contact support.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
