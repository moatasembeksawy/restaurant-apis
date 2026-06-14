<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Services;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Exceptions\PlanLimitExceededException;

class PlanLimitService
{
    public function __construct(private readonly Tenant $tenant) {}

    public function check(string $resource): void
    {
        $limits = $this->tenant->planLimits();

        match ($resource) {
            'users' => $this->checkUsers($limits['max_users']),
            'branches' => $this->checkBranches($limits['max_branches']),
            'orders' => $this->checkMonthlyOrders($limits['max_orders_per_month']),
            default => null,
        };
    }

    private function checkUsers(int $max): void
    {
        if ($max === PHP_INT_MAX) {
            return;
        }

        $count = $this->tenant->users()->count();

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

        $count = $this->tenant->branches()->count();

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

        $count = Order::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
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
}
