<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Phase 3 — all routes gated by 'inventory' feature
Route::middleware('feature:inventory')->group(function (): void {
    Route::get('inventory/ingredients', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'index']);
    Route::post('inventory/ingredients', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'store']);
    Route::patch('inventory/ingredients/{ingredient}', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'update']);
    Route::post('inventory/movements', [\App\Modules\Inventory\Stock\Http\Controllers\StockMovementController::class, 'store']);
    Route::get('inventory/low-stock', [\App\Modules\Inventory\Stock\Http\Controllers\IngredientController::class, 'lowStock']);
    Route::get('menu/items/{item}/cost', [\App\Modules\Inventory\Recipes\Http\Controllers\RecipeController::class, 'cost']);
});
