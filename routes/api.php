<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function() {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // API Keys Mangagement
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store' ]);
        Route::delete('/api-keys/{id}', [ApiKeyController::class, 'destroy']);
        Route::post('/api-keys/{id}/rotate', [ApiKeyController::class, 'rtoate']);
    });
});