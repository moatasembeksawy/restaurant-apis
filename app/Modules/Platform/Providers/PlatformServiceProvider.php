<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Services\PlatformAdminAuthService;
use App\Modules\Platform\Services\TenantFinancialPurgeService;
use App\Modules\Platform\Services\TenantManagementService;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformAdminAuthService::class);
        $this->app->singleton(TenantManagementService::class);
        $this->app->singleton(TenantFinancialPurgeService::class);
    }

    public function boot(): void {}
}
