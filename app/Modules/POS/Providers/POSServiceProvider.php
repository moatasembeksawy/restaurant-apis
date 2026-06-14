<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\POS\Billing\Services\ETAService;
use App\Modules\POS\Billing\Services\PaymentSettlementService;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Shared\Infrastructure\ETA\ETACredentialResolver;
use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderPlacementService::class);
        $this->app->singleton(ETACredentialResolver::class);
        $this->app->singleton(ETAService::class);
        $this->app->singleton(PaymentSettlementService::class);
    }

    public function boot(): void {}
}
