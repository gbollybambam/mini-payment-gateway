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
        // 1. Get the token
        $rawToken = $request->bearerToken();

        if (! $rawToken) {
            return response()->json(['status' => 'error', 'message' => 'API key missing.'], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Aggressively strip spaces, newlines, and accidental quotes
        $token = trim($rawToken, " \t\n\r\0\x0B\"'");

        // 3. Hash it for the DB lookup
        $hashedToken = hash('sha256', $token);

        /* 
         * --- DEBUGGING BLOCK ---
         * Uncomment the dd() below and make a request from Postman. 
         * Check the exact string in 'received_token'. Does it look correct? 
         * Check 'exists_in_db' to see if the hash matches.
         */
        // dd([
        //     'raw_from_postman' => $rawToken,
        //     'cleaned_token'   => $token,
        //     'hashed_version'  => $hashedToken,
        //     'exists_in_db'    => ApiKey::where('secret_key', $hashedToken)->exists()
        // ]);

        // 4. Look it up
        $apiKey = ApiKey::with('merchant')->where('secret_key', $hashedToken)->first();

        if (! $apiKey || $apiKey->revoked_at !== null) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or revoked API key.'], Response::HTTP_UNAUTHORIZED);
        }

        // --- STRICT EXPIRATION CHECK ---
        if ($apiKey->expires_at !== null && $apiKey->expires_at->isPast()) {
            return response()->json(['status' => 'error', 'message' => 'This API key has expired. Please generate a new one.'], Response::HTTP_UNAUTHORIZED);
        }

        // Update last used timestamp
        $apiKey->update(['last_used_at' => now()]);

        // Attach the merchant AND the scopes
        $request->merge([
            'merchant' => $apiKey->merchant,
            'api_scopes' => $apiKey->scopes ?? ['*']
        ]);

        return $next($request);
    }
}
