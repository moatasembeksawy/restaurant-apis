<?php

declare(strict_types=1);

use App\Modules\Delivery\Customers\Http\Controllers\CustomerAddressController;
use App\Modules\Delivery\Customers\Http\Controllers\CustomerController;
use App\Modules\Delivery\Riders\Http\Controllers\RiderController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:customers')->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::patch('customers/{customer}', [CustomerController::class, 'update']);
    Route::get('customers/{customer}/orders', [CustomerController::class, 'orders']);
    Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index']);
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store']);
    Route::patch('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update']);
    Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy']);
});

Route::middleware('feature:riders')->group(function (): void {
    Route::get('riders', [RiderController::class, 'index']);
    Route::get('riders/deliveries', [RiderController::class, 'myDeliveries']);
    Route::get('deliveries/unassigned', [RiderController::class, 'unassigned']);
    Route::post('orders/{order}/assign-rider', [RiderController::class, 'assign']);
    Route::patch('orders/{order}/delivery-status', [RiderController::class, 'updateDeliveryStatus']);
});
