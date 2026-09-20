<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // MUST specify guard('admin') so it doesn't log them in as a merchant!
        if (Auth::guard('admin')->attempt($credentials)) {
            // This is the magic line that creates the persistent cookie
            $request->session()->regenerate();

            return response()->json([
                'status' => 'success',
                'message' => 'Admin authenticated.'
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid admin credentials.'
        ], 401);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['status' => 'success']);
    }
}
