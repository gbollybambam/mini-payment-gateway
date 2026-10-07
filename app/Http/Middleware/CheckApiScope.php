<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiScope
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $scope
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $scopes = $request->input('api_scopes', []);

        // If the key has the wildcard '*' scope, they have full access.
        if (in_array('*', $scopes)) {
            return $next($request);
        }

        // If the required scope is NOT in their allowed scopes array, block them.
        if (!in_array($scope, $scopes)) {
            return response()->json([
                'status' => 'error',
                'message' => "Forbidden. This API key lacks the required '{$scope}' permission scope."
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
