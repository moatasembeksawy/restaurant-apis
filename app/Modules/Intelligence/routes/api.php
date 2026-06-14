<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::middleware('feature:ai_reports')->group(function (): void {
    Route::get('reports/ai-summary', [\App\Modules\Intelligence\Reports\Http\Controllers\AIReportController::class, 'weekly']);
});

Route::middleware('feature:aggregator_analytics')->group(function (): void {
    Route::get('analytics/aggregators', [\App\Modules\Intelligence\Analytics\Http\Controllers\AggregatorController::class, 'compare']);
});

Route::middleware('feature:loyalty')->group(function (): void {
    Route::get('loyalty/customers/{customer}', [\App\Modules\Intelligence\Loyalty\Http\Controllers\LoyaltyController::class, 'show']);
    Route::post('loyalty/customers/{customer}/redeem', [\App\Modules\Intelligence\Loyalty\Http\Controllers\LoyaltyController::class, 'redeem']);
});
