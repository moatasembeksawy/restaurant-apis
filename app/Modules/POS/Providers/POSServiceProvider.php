<?php

declare(strict_types=1);

namespace App\Modules\POS\Providers;

use App\Modules\POS\Orders\Services\OrderPlacementService;
use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderPlacementService::class);
    }

    public function boot(): void {}
}
