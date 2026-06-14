<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Services;

use InvalidArgumentException;

class PlanPricingService
{
    /**
     * @return array{name: string, name_ar: string, monthly_egp: int, rank: int}
     */
    public function plan(string $plan): array
    {
        $config = config("billing.plans.{$plan}");

        if (! $config) {
            throw new InvalidArgumentException("Unknown plan: {$plan}");
        }

        return $config;
    }

    public function amountCents(string $plan): int
    {
        return $this->plan($plan)['monthly_egp'] * 100;
    }

    public function merchantReference(int $tenantId, string $plan): string
    {
        return "tenant_{$tenantId}_{$plan}";
    }

    /**
     * Parse merchant reference back to tenant + plan.
     *
     * @return array{tenant_id: int, plan: string}
     */
    public function parseMerchantReference(string $reference): array
    {
        $parts = explode('_', $reference);

        if (count($parts) < 3 || $parts[0] !== 'tenant') {
            throw new InvalidArgumentException("Invalid merchant reference: {$reference}");
        }

        return [
            'tenant_id' => (int) $parts[1],
            'plan' => $parts[2],
        ];
    }

    public function isValidPlan(string $plan): bool
    {
        return (bool) config("billing.plans.{$plan}");
    }

    public function rank(string $plan): int
    {
        return $this->plan($plan)['rank'];
    }

    public function isDowngrade(string $currentPlan, string $targetPlan): bool
    {
        return $this->rank($targetPlan) < $this->rank($currentPlan);
    }
}
