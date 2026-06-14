<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Providers;

use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PlanLimitService::class, function (): PlanLimitService {
            $tenant = app()->bound('tenant') ? app('tenant') : new Tenant();

            return new PlanLimitService($tenant);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations'));
    }
}
