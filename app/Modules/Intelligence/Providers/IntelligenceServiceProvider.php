<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Providers;

use App\Modules\Intelligence\Analytics\Services\AggregatorAnalyticsService;
use App\Modules\Intelligence\Loyalty\Services\LoyaltyService;
use App\Modules\Intelligence\Reports\Services\AIReportService;
use Illuminate\Support\ServiceProvider;

class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoyaltyService::class);
        $this->app->singleton(AIReportService::class);
        $this->app->singleton(AggregatorAnalyticsService::class);
    }

    public function boot(): void {}
}
