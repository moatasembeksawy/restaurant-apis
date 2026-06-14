<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware('feature:inventory')->group(function (): void {
    Route::get('inventory/ingredients', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'index']);
    Route::post('inventory/ingredients', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'store']);
    Route::get('inventory/ingredients/{ingredient}', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'show']);
    Route::patch('inventory/ingredients/{ingredient}', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'update']);
    Route::get('inventory/low-stock', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'lowStock']);

    Route::get('inventory/movements', [\App\Modules\Inventory\Stock\Http\Controllers\StockMovementController::class, 'index']);
    Route::post('inventory/movements', [\App\Modules\Inventory\Stock\Http\Controllers\StockMovementController::class, 'store']);

    Route::middleware('feature:multi_branch')->group(function (): void {
        Route::get('inventory/transfers', [\App\Modules\Inventory\Stock\Http\Controllers\StockTransferController::class, 'index']);
        Route::post('inventory/transfers', [\App\Modules\Inventory\Stock\Http\Controllers\StockTransferController::class, 'store']);
    });

    Route::get('menu/items/{item}/cost', [\App\Modules\Inventory\Recipes\Http\Controllers\RecipeController::class, 'cost']);
    Route::get('menu/items/{item}/recipe', [\App\Modules\Inventory\Recipes\Http\Controllers\RecipeController::class, 'show']);
    Route::put('menu/items/{item}/recipe', [\App\Modules\Inventory\Recipes\Http\Controllers\RecipeController::class, 'sync']);
});

Route::middleware('feature:suppliers')->group(function (): void {
    Route::get('inventory/suppliers', [\App\Modules\Inventory\Stock\Http\Controllers\SupplierController::class, 'index']);
    Route::post('inventory/suppliers', [\App\Modules\Inventory\Stock\Http\Controllers\SupplierController::class, 'store']);
    Route::patch('inventory/suppliers/{supplier}', [\App\Modules\Inventory\Stock\Http\Controllers\SupplierController::class, 'update']);

    Route::get('inventory/purchase-orders', [\App\Modules\Inventory\Stock\Http\Controllers\PurchaseOrderController::class, 'index']);
    Route::post('inventory/purchase-orders', [\App\Modules\Inventory\Stock\Http\Controllers\PurchaseOrderController::class, 'store']);
    Route::get('inventory/purchase-orders/{purchaseOrder}', [\App\Modules\Inventory\Stock\Http\Controllers\PurchaseOrderController::class, 'show']);
    Route::post('inventory/purchase-orders/{purchaseOrder}/submit', [\App\Modules\Inventory\Stock\Http\Controllers\PurchaseOrderController::class, 'submit']);
    Route::post('inventory/purchase-orders/{purchaseOrder}/receive', [\App\Modules\Inventory\Stock\Http\Controllers\PurchaseOrderController::class, 'receive']);
});
