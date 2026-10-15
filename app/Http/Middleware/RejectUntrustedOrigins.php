<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectUntrustedOrigins
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! $request->is('api/*')
            || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $request->is('api/webhooks/*')
            || ! $request->headers->has('Origin')
        ) {
            return $next($request);
        }

        $origin = $this->normalizeOrigin($request->headers->get('Origin'));
        $trustedOrigins = array_filter([
            $this->normalizeOrigin(config('app.url')),
            $this->normalizeOrigin(config('app.frontend_url')),
        ]);

        if (! $origin || ! in_array($origin, $trustedOrigins, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Untrusted request origin.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function normalizeOrigin(?string $origin): ?string
    {
        if (! $origin) {
            return null;
        }

        $parsed = parse_url(trim($origin));
        if (! is_array($parsed) || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return strtolower($parsed['scheme'] . '://' . $parsed['host'] . $port);
    }
}
