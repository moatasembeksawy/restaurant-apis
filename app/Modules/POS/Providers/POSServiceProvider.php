<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\POS\Billing\Services\ETAService;
use App\Modules\POS\Billing\Services\PaymentSettlementService;
use App\Modules\POS\Menu\Services\ItemPartExpansionService;
use App\Modules\POS\Menu\Services\MenuItemService;
use App\Modules\POS\Offers\Services\OfferEngine;
use App\Modules\POS\Offers\Services\OfferService;
use App\Modules\POS\Offline\Commands\PosHubServeCommand;
use App\Modules\POS\Offline\Commands\PosHubSyncCommand;
use App\Modules\POS\Offline\Hub\LocalHubStore;
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
        $this->app->singleton(MenuItemService::class);
        $this->app->singleton(ItemPartExpansionService::class);
        $this->app->singleton(PackageService::class);
        $this->app->singleton(PackageExpansionService::class);
        $this->app->singleton(OfferEngine::class);
        $this->app->singleton(OfferService::class);
        $this->app->singleton(ETACredentialResolver::class);
        $this->app->singleton(ETAService::class);
        $this->app->singleton(PaymentSettlementService::class);
        $this->app->singleton(LocalHubStore::class, fn (): LocalHubStore => new LocalHubStore(
            (string) config('pos-hub.path'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PosHubServeCommand::class,
                PosHubSyncCommand::class,
            ]);
        }
    }
}
