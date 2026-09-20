<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetController extends Controller
{
    /**
     * Send password reset token/link (Zero-Knowledge / Anti-Enumeration)
     */
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        // We execute the reset broker, but we completely ignore the result status.
        Password::broker('merchants')->sendResetLink($request->only('email'));

        // ALWAYS return 200 OK. Attackers get zero clues.
        return response()->json([
            'status' => 'success',
            'message' => 'If an account with that email exists, a password reset link has been sent.'
        ], Response::HTTP_OK);
    }

    /**
     * Reset password using token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('merchants')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($merchant, string $password) {
                $merchant->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $merchant->tokens()->delete(); // Revoke all active API sessions for security
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['status' => 'success', 'message' => 'Password reset successfully.'])
            : response()->json(['status' => 'error', 'message' => 'Invalid or expired password reset token.'], Response::HTTP_BAD_REQUEST);
    }
}
