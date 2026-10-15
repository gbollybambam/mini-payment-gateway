<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $merchant = $request->merchant;

        if (! $merchant && ! ($user instanceof Merchant) && ! ($user instanceof MerchantUser)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden: This account cannot access merchant operations.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user instanceof Merchant) {
            $merchant = $user;
        }

        // 1. Check if the authenticated user is a Team Member (MerchantUser) and is deactivated
        if ($user instanceof MerchantUser) {
            if (!$user->is_active) {
                if ($user->exists && method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Your team account has been deactivated or suspended by the administrator.',
                ], Response::HTTP_FORBIDDEN);
            }

            $merchant = $user->merchant;
        }

        // 2. Check if the Merchant (Owner or context) is inactive or suspended.
        if ($merchant && ((! $merchant->is_active) || strtoupper($merchant->status ?? '') === 'SUSPENDED')) {
            if ($user) {
                if ($user->exists && method_exists($user, 'tokens')) {
                    $user->tokens()->delete();
                }

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Your merchant account has been suspended. Please contact support.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
