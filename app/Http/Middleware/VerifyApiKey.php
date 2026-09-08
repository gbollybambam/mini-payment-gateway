<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([ 'status' => 'error', 'message' => 'API key missing.' ], Response::HTTP_UNAUTHORIZED);
        }

        // Hash the provided plaintext token to look it up securely
        $hashedToken = hash('sha256', $token);

        $apiKey = ApiKey::with('merchant')->where('secret_key', $hashedToken)->first();

        if (! $apiKey || $apiKey->revoked_at !== null) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or revoked API key.'], Response::HTTP_UNAUTHORIZED);
        }

        // Update last used timestamp
        $apiKey->update(['last_used_at' => now()]);

        // Attach the merchant to the request for the controller to use
        $request->merge(['merchant' => $apiKey->merchant]);

        return $next($request);
    }
}
