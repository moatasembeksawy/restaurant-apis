<?php

declare(strict_types=1);

use App\Modules\POS\Billing\Http\Controllers\InvoiceController;
use App\Modules\POS\Billing\Http\Controllers\PaymentController;
use App\Modules\POS\Billing\Http\Controllers\ReportController;
use App\Modules\POS\Kitchen\Http\Controllers\KitchenController;
use App\Modules\POS\Menu\Http\Controllers\MenuCategoryController;
use App\Modules\POS\Menu\Http\Controllers\MenuItemController;
use App\Modules\POS\Orders\Http\Controllers\OrderController;
use App\Modules\POS\Orders\Http\Controllers\OrderItemController;
use App\Modules\POS\Print\Http\Controllers\PrintController;
use App\Modules\POS\Tables\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

// ── Floor Tables ───────────────────────────────────────────────────────────────
Route::middleware('permission:tables.view')->group(function (): void {
    Route::get('tables', [TableController::class, 'index']);
    Route::get('tables/{table}', [TableController::class, 'show']);
});
Route::post('tables', [TableController::class, 'store'])->middleware('permission:tables.create');
Route::match(['put', 'patch'], 'tables/{table}', [TableController::class, 'update'])->middleware('permission:tables.update');
Route::delete('tables/{table}', [TableController::class, 'destroy'])->middleware('permission:tables.delete');
Route::patch('tables/{table}/status', [TableController::class, 'updateStatus'])->middleware('permission:tables.update');

// ── Menu ───────────────────────────────────────────────────────────────────────
Route::middleware('permission:menu.view')->group(function (): void {
    Route::get('menu/categories', [MenuCategoryController::class, 'index']);
    Route::get('menu/categories/{category}', [MenuCategoryController::class, 'show']);
    Route::get('menu/items', [MenuItemController::class, 'index']);
    Route::get('menu/items/{item}', [MenuItemController::class, 'show']);
});
Route::post('menu/categories', [MenuCategoryController::class, 'store'])->middleware('permission:menu.create');
Route::match(['put', 'patch'], 'menu/categories/{category}', [MenuCategoryController::class, 'update'])->middleware('permission:menu.update');
Route::delete('menu/categories/{category}', [MenuCategoryController::class, 'destroy'])->middleware('permission:menu.delete');
Route::post('menu/items', [MenuItemController::class, 'store'])->middleware('permission:menu.create');
Route::match(['put', 'patch'], 'menu/items/{item}', [MenuItemController::class, 'update'])->middleware('permission:menu.update');
Route::delete('menu/items/{item}', [MenuItemController::class, 'destroy'])->middleware('permission:menu.delete');
Route::patch('menu/items/{item}/toggle', [MenuItemController::class, 'toggle'])->middleware('permission:menu.update');
Route::post('menu/items/{item}/photo', [MenuItemController::class, 'uploadPhoto'])->middleware('permission:menu.update');
Route::delete('menu/items/{item}/photo', [MenuItemController::class, 'deletePhoto'])->middleware('permission:menu.update');

// ── Orders ─────────────────────────────────────────────────────────────────────
Route::middleware('permission:orders.view')->group(function (): void {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::get('orders/{order}/print/kitchen', [PrintController::class, 'kitchenTicket']);
    Route::get('orders/{order}/print/receipt', [PrintController::class, 'receipt']);
});
Route::post('orders', [OrderController::class, 'store'])->middleware('permission:orders.create');
Route::match(['put', 'patch'], 'orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update');
Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware('permission:orders.update');
Route::post('orders/{order}/items', [OrderItemController::class, 'store'])->middleware('permission:orders.update');
Route::delete('orders/{order}/items/{item}', [OrderItemController::class, 'destroy'])->middleware('permission:orders.update');

// ── Kitchen Display ────────────────────────────────────────────────────────────
Route::middleware('feature:kitchen_display')->group(function (): void {
    Route::get('kitchen/queue', [KitchenController::class, 'queue'])->middleware('permission:kitchen.view');
    Route::patch('kitchen/items/{item}/done', [KitchenController::class, 'markDone'])->middleware('permission:kitchen.update');
});

// ── Payments & Billing ─────────────────────────────────────────────────────────
Route::post('orders/{order}/pay', [PaymentController::class, 'settle'])->middleware('permission:payments.process');
Route::post('orders/{order}/refund', [PaymentController::class, 'refund'])->middleware('permission:payments.refund');
Route::middleware('permission:payments.process')->group(function (): void {
    Route::get('invoices/failed', [InvoiceController::class, 'failed']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('invoices/{invoice}/resubmit', [InvoiceController::class, 'resubmit']);
});
Route::middleware('permission:reports.view')->group(function (): void {
    Route::get('reports/daily', [ReportController::class, 'daily']);
    Route::get('reports/cash-summary', [ReportController::class, 'cashSummary']);
    Route::get('reports/top-items', [ReportController::class, 'topItems']);
});
