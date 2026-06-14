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

Route::middleware('feature:whatsapp_marketing')->group(function (): void {
    Route::get('marketing/segments', [\App\Modules\Intelligence\Marketing\Http\Controllers\MarketingController::class, 'segments']);
    Route::get('marketing/campaigns', [\App\Modules\Intelligence\Marketing\Http\Controllers\MarketingController::class, 'index']);
    Route::post('marketing/broadcast', [\App\Modules\Intelligence\Marketing\Http\Controllers\MarketingController::class, 'broadcast']);
});
