<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificationController extends Controller
{
    /**
     * Resend verification email.
     */
    public function resend(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email address is already verified.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'status' => 'success',
            'message' => 'Veification link sent to your email.',
        ], Response::HTTP_OK);
    }

    /**
     * Mark the merchant's email as verified.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $merchant = Merchant::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($merchant->getEmailForVerification()))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired verification URL.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($merchant->markEmailAsVerified()) {
            event(new Verified($merchant));
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Email has been successfully verified.',
        ], Response::HTTP_OK);
    }
}
