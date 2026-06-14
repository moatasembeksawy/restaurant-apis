<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// ── Public auth routes (no Sanctum auth required) ─────────────────────────────
Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
Route::get('auth/verify-email', [AuthController::class, 'verifyEmail'])
    ->name('api.verification.verify');
Route::post('auth/device/login', [AuthController::class, 'deviceLogin'])
    ->middleware('tenant');
Route::post('auth/device/kitchen', [AuthController::class, 'kitchenLogin'])
    ->middleware('tenant');

// ── Authenticated auth routes ──────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::delete('auth/token', [AuthController::class, 'logout']);
    Route::post('auth/email/verification-notification', [AuthController::class, 'sendVerificationNotification']);
});
