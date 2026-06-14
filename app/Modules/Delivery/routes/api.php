<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Phase 2 — all routes gated by 'delivery' feature
Route::middleware('feature:delivery')->group(function (): void {
    Route::get('customers', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'index']);
    Route::get('customers/{customer}', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'show']);
    Route::get('riders', [\App\Modules\Delivery\Riders\Http\Controllers\RiderController::class, 'index']);
    Route::post('orders/{order}/assign-rider', [\App\Modules\Delivery\Riders\Http\Controllers\RiderController::class, 'assign']);
});
