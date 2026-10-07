<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class TeamInviteController extends Controller
{
    public function accept(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $cacheKey = 'team_invite_' . $request->token;
        $cachedEmail = Cache::get($cacheKey);

        if (! $cachedEmail || $cachedEmail !== $request->email) {
            return response()->json([
                'status' => 'error',
                'message' => 'This invitation link is invalid or has expired.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $teamUser = MerchantUser::where('email', $request->email)->firstOrFail();

        $teamUser->update([
            'password' => Hash::make($request->password),
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Invalidate the token so it can't be reused
        Cache::forget($cacheKey);

        return response()->json([
            'status' => 'success',
            'message' => 'Account activated successfully. You can now log in.',
        ], Response::HTTP_OK);
    }
}
