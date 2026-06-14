<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware('feature:customers')->group(function (): void {
    Route::get('customers', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'index']);
    Route::post('customers', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'store']);
    Route::get('customers/{customer}', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'show']);
    Route::patch('customers/{customer}', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'update']);
    Route::get('customers/{customer}/orders', [\App\Modules\Delivery\Customers\Http\Controllers\CustomerController::class, 'orders']);
});

Route::middleware('feature:riders')->group(function (): void {
    Route::get('riders', [\App\Modules\Delivery\Riders\Http\Controllers\RiderController::class, 'index']);
    Route::get('riders/deliveries', [\App\Modules\Delivery\Riders\Http\Controllers\RiderController::class, 'myDeliveries']);
    Route::post('orders/{order}/assign-rider', [\App\Modules\Delivery\Riders\Http\Controllers\RiderController::class, 'assign']);
    Route::patch('orders/{order}/delivery-status', [\App\Modules\Delivery\Riders\Http\Controllers\RiderController::class, 'updateDeliveryStatus']);
});
