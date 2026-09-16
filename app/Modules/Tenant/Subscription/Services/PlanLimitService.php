<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Services;

use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Exceptions\PlanLimitExceededException;
use RuntimeException;

class PlanLimitService
{
    public function check(string $resource): void
    {
        $limits = $this->tenant()->planLimits();

        match ($resource) {
            'users' => $this->checkUsers($limits['max_users']),
            'branches' => $this->checkBranches($limits['max_branches']),
            'orders' => $this->checkMonthlyOrders($limits['max_orders_per_month']),
            'packages' => $this->checkPackages($limits['max_packages']),
            'active_offers' => $this->checkActiveOffers($limits['max_active_offers']),
            default => null,
        };
    }

    private function tenant(): Tenant
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! $tenant instanceof Tenant || blank($tenant->plan)) {
            throw new RuntimeException('Tenant context is required to check plan limits.');
        }

        return $tenant;
    }

    private function checkUsers(int $max): void
    {
        if ($max === PHP_INT_MAX) {
            return;
        }

        $count = $this->tenant()->users()->count();

        if ($count >= $max) {
            throw new PlanLimitExceededException(
                "You have reached the maximum of {$max} users on your plan. Upgrade to add more.",
                'users',
                $max,
            );
        }
    }

    private function checkBranches(int $max): void
    {
        if ($max === PHP_INT_MAX) {
            return;
        }

        $count = $this->tenant()->branches()->count();

        if ($count >= $max) {
            throw new PlanLimitExceededException(
                "Your plan allows {$max} branch(es). Upgrade to add more branches.",
                'branches',
                $max,
            );
        }
    }

    private function checkMonthlyOrders(int $max): void
    {
        if ($max === PHP_INT_MAX) {
            return;
        }

        $tenant = $this->tenant();

        $count = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        if ($count >= $max) {
            throw new PlanLimitExceededException(
                "You have reached {$max} orders this month. Upgrade your plan to continue.",
                'orders',
                $max,
            );
        }
    }

    private function checkPackages(int $max): void
    {
        if ($max === PHP_INT_MAX) {
            return;
        }

        $count = MenuPackage::query()->count();

        if ($count >= $max) {
            throw new PlanLimitExceededException(
                "You have reached the maximum of {$max} packages on your plan. Upgrade to add more.",
                'packages',
                $max,
            );
        }
    }

    private function checkActiveOffers(int $max): void
    {
        if ($max === PHP_INT_MAX) {
            return;
        }

        $count = Offer::query()->where('is_active', true)->count();

        if ($count >= $max) {
            throw new PlanLimitExceededException(
                "You have reached the maximum of {$max} active offers on your plan. Upgrade to add more.",
                'active_offers',
                $max,
            );
        }
    }
}
