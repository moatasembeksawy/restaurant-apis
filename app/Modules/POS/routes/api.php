<?php

declare(strict_types=1);

use App\Modules\POS\Tables\Http\Controllers\TableController;
use App\Modules\POS\Menu\Http\Controllers\MenuCategoryController;
use App\Modules\POS\Menu\Http\Controllers\MenuItemController;
use App\Modules\POS\Orders\Http\Controllers\OrderController;
use App\Modules\POS\Orders\Http\Controllers\OrderItemController;
use App\Modules\POS\Kitchen\Http\Controllers\KitchenController;
use App\Modules\POS\Billing\Http\Controllers\PaymentController;
use App\Modules\POS\Billing\Http\Controllers\ReportController;
use App\Modules\POS\Print\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

// ── Floor Tables ───────────────────────────────────────────────────────────────
Route::apiResource('tables', TableController::class);
Route::patch('tables/{table}/status', [TableController::class, 'updateStatus']);

// ── Menu ───────────────────────────────────────────────────────────────────────
Route::apiResource('menu/categories', MenuCategoryController::class);
Route::apiResource('menu/items', MenuItemController::class);
Route::patch('menu/items/{item}/toggle', [MenuItemController::class, 'toggle']);
Route::post('menu/items/{item}/photo', [MenuItemController::class, 'uploadPhoto']);
Route::delete('menu/items/{item}/photo', [MenuItemController::class, 'deletePhoto']);

// ── Orders ─────────────────────────────────────────────────────────────────────
Route::apiResource('orders', OrderController::class)->except(['destroy']);
Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
Route::post('orders/{order}/items', [OrderItemController::class, 'store']);
Route::delete('orders/{order}/items/{item}', [OrderItemController::class, 'destroy']);

// ── Kitchen Display ────────────────────────────────────────────────────────────
Route::middleware('feature:kitchen_display')->group(function (): void {
    Route::get('kitchen/queue', [KitchenController::class, 'queue']);
    Route::patch('kitchen/items/{item}/done', [KitchenController::class, 'markDone']);
});

// ── Payments & Billing ─────────────────────────────────────────────────────────
Route::post('orders/{order}/pay', [PaymentController::class, 'settle']);
Route::get('invoices/failed', [\App\Modules\POS\Billing\Http\Controllers\InvoiceController::class, 'failed']);
Route::get('invoices/{invoice}', [\App\Modules\POS\Billing\Http\Controllers\InvoiceController::class, 'show']);
Route::post('invoices/{invoice}/resubmit', [\App\Modules\POS\Billing\Http\Controllers\InvoiceController::class, 'resubmit']);
Route::get('orders/{order}/print/kitchen', [PrintController::class, 'kitchenTicket']);
Route::get('orders/{order}/print/receipt', [PrintController::class, 'receipt']);
Route::get('reports/daily', [ReportController::class, 'daily']);
Route::get('reports/cash-summary', [ReportController::class, 'cashSummary']);
Route::get('reports/top-items', [ReportController::class, 'topItems']);
