<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Models\MerchantUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only merchant owners and team members may use merchant UI routes.
        if (! ($user instanceof Merchant) && ! ($user instanceof MerchantUser)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden: This account cannot access merchant operations.',
            ], 403);
        }

        // Primary Merchant Owners have absolute authority. Let them pass.
        if ($user instanceof Merchant) {
            return $next($request);
        }

        // If the user is a Team Member, verify they hold one of the permitted roles
        if (!in_array($user->role, $roles)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Forbidden: Your current access role (' . strtoupper($user->role) . ') does not permit this action.'
            ], 403);
        }

        return $next($request);
    }
}
