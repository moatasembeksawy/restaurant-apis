<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\AdminAuthController;
use App\Modules\Platform\Http\Controllers\AdminDashboardController;
use App\Modules\Platform\Http\Controllers\AdminTenantController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AdminAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'platform.admin'])->group(function (): void {
    Route::get('auth/me', [AdminAuthController::class, 'me']);
    Route::post('auth/logout', [AdminAuthController::class, 'logout']);

    Route::get('dashboard', [AdminDashboardController::class, 'stats']);

    Route::get('tenants', [AdminTenantController::class, 'index']);
    Route::post('tenants', [AdminTenantController::class, 'store']);
    Route::get('tenants/{tenant}', [AdminTenantController::class, 'show']);
    Route::patch('tenants/{tenant}', [AdminTenantController::class, 'update']);
    Route::patch('tenants/{tenant}/plan', [AdminTenantController::class, 'updatePlan']);
    Route::patch('tenants/{tenant}/status', [AdminTenantController::class, 'updateStatus']);
    Route::patch('tenants/{tenant}/features', [AdminTenantController::class, 'updateFeatures']);
});
