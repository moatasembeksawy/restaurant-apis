<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Stock\Services\StockService;
use App\Modules\Inventory\Stock\Services\StockTransferService;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StockService::class);
        $this->app->singleton(StockTransferService::class);
    }

    public function boot(): void {}
}
