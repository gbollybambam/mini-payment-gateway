<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

use Illuminate\Http\Request;

class PasswordResetController extends Controller
{
    /**
     * Send password reset token/link.
     */

    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('merchants')->sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['status' => 'success', 'message' => __($status)], Response::HTTP_OK)
            : response()->json(['status' => 'error', 'message' => __($status)], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Reset password usind token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('merchants')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($merchant, string $password) {
                $merchant->forceFill([
                    'password' => Hash::make($password),
                ])->save();

                $merchant->tokens()->delete();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['status' => 'success', 'message' => __($status)], Response::HTTP_OK)
            : response()->json(['status' => 'error', 'message' => __($status)], Response::HTTP_BAD_REQUEST);
    }
}
