<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * List all active sessions / tokens for this merchant.
     */
    public function index(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $sessions = $request->user()->tokens()->get()->map(function ($token) use ($currentTokenId) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'is_current' => $token->id === $currentTokenId,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $sessions,
        ], Response::HTTP_OK);
    }

    /**
     * Revoke all other active sessions except the current one.
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()->id;

        $request->user()->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'All other sessions have been revoked.',
        ], Response::HTTP_OK);
    }

    /**
     * Revoke a specific sesion
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $request->user()->tokens()->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Session revoked successfully'
        ], Response::HTTP_OK);
    }

}
