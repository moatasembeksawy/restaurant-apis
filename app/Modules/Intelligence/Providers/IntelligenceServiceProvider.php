<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Providers;

use App\Modules\Intelligence\Analytics\Services\AggregatorAnalyticsService;
use App\Modules\Intelligence\Loyalty\Services\LoyaltyService;
use App\Modules\Intelligence\Marketing\Services\WhatsAppMarketingService;
use App\Modules\Intelligence\Reports\Services\AIReportService;
use App\Modules\Intelligence\Reports\Services\LLMNarrativeService;
use Illuminate\Support\ServiceProvider;

class IntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoyaltyService::class);
        $this->app->singleton(AIReportService::class);
        $this->app->singleton(LLMNarrativeService::class);
        $this->app->singleton(AggregatorAnalyticsService::class);
        $this->app->singleton(WhatsAppMarketingService::class);
    }

    public function boot(): void {}
}
