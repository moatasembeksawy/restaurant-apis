<?php

declare(strict_types=1);

use App\Modules\Inventory\Recipes\Http\Controllers\RecipeController;
use App\Modules\Inventory\Stock\Http\Controllers\IngredientController;
use App\Modules\Inventory\Stock\Http\Controllers\StockCountController;
use App\Modules\Inventory\Stock\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Stock\Http\Controllers\StockTransferController;
use App\Modules\Inventory\Suppliers\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Suppliers\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:inventory')->group(function (): void {
    Route::middleware('permission:inventory.view')->group(function (): void {
        Route::get('inventory/ingredients', [IngredientController::class, 'index']);
        Route::get('inventory/ingredients/{ingredient}', [IngredientController::class, 'show']);
        Route::get('inventory/low-stock', [IngredientController::class, 'lowStock']);
        Route::get('inventory/movements', [StockMovementController::class, 'index']);
        Route::get('inventory/stock-counts', [StockCountController::class, 'index']);
        Route::get('inventory/stock-counts/{stockCount}', [StockCountController::class, 'show']);
        Route::get('menu/items/{item}/cost', [RecipeController::class, 'cost']);
        Route::get('menu/items/{item}/recipe', [RecipeController::class, 'show']);
    });

    Route::middleware('permission:inventory.manage')->group(function (): void {
        Route::post('inventory/ingredients', [IngredientController::class, 'store']);
        Route::patch('inventory/ingredients/{ingredient}', [IngredientController::class, 'update']);
        Route::post('inventory/movements', [StockMovementController::class, 'store']);
        Route::post('inventory/stock-counts', [StockCountController::class, 'store']);
        Route::put('inventory/stock-counts/{stockCount}/lines', [StockCountController::class, 'upsertLine']);
        Route::post('inventory/stock-counts/{stockCount}/complete', [StockCountController::class, 'complete']);
        Route::post('inventory/stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel']);
        Route::put('menu/items/{item}/recipe', [RecipeController::class, 'sync']);
    });

    Route::middleware(['feature:multi_branch', 'permission:inventory.manage'])->group(function (): void {
        Route::get('inventory/transfers', [StockTransferController::class, 'index']);
        Route::post('inventory/transfers', [StockTransferController::class, 'store']);
    });
});

Route::middleware('feature:suppliers')->group(function (): void {
    Route::middleware('permission:inventory.view')->group(function (): void {
        Route::get('inventory/suppliers', [SupplierController::class, 'index']);
        Route::get('inventory/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('inventory/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    });

    Route::middleware('permission:suppliers.manage')->group(function (): void {
        Route::post('inventory/suppliers', [SupplierController::class, 'store']);
        Route::patch('inventory/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::post('inventory/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::post('inventory/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
        Route::post('inventory/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
    });
});
