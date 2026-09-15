<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\POS\Billing\Services\ETAService;
use App\Modules\POS\Billing\Services\PaymentSettlementService;
use App\Modules\POS\Offers\Services\OfferEngine;
use App\Modules\POS\Offers\Services\OfferService;
use App\Modules\POS\Orders\Services\OrderLineService;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\POS\Packages\Services\PackageExpansionService;
use App\Modules\POS\Packages\Services\PackageService;
use App\Shared\Infrastructure\ETA\ETACredentialResolver;
use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderPlacementService::class);
        $this->app->singleton(OrderLineService::class);
        $this->app->singleton(PackageService::class);
        $this->app->singleton(PackageExpansionService::class);
        $this->app->singleton(OfferEngine::class);
        $this->app->singleton(OfferService::class);
        $this->app->singleton(ETACredentialResolver::class);
        $this->app->singleton(ETAService::class);
        $this->app->singleton(PaymentSettlementService::class);
    }

    public function boot(): void {}
}
