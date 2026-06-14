<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Providers;

use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Reports\Services\BranchComparisonService;
use App\Modules\Tenant\Services\CustomDomainService;
use App\Modules\Tenant\Services\StaffService;
use App\Modules\Tenant\Services\TenantOnboardingService;
use App\Modules\Tenant\Services\TenantSettingsService;
use App\Modules\Tenant\Staff\Services\StaffShiftService;
use App\Modules\Tenant\Subscription\Commands\ProcessSubscriptionsCommand;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use App\Modules\Tenant\Subscription\Services\SubscriptionLifecycleService;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SubscriptionService::class);
        $this->app->singleton(SubscriptionLifecycleService::class);
        $this->app->singleton(TenantOnboardingService::class);
        $this->app->singleton(TenantSettingsService::class);
        $this->app->singleton(CustomDomainService::class);
        $this->app->singleton(StaffService::class);
        $this->app->singleton(StaffShiftService::class);
        $this->app->singleton(BranchComparisonService::class);

        $this->app->bind(PlanLimitService::class, function (): PlanLimitService {
            $tenant = app()->bound('tenant') ? app('tenant') : new Tenant;

            return new PlanLimitService($tenant);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                ProcessSubscriptionsCommand::class,
            ]);
        }
    }
}
