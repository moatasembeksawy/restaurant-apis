<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'subdomain' => Str::slug(fake()->unique()->word()),
            'custom_domain' => null,
            'locale' => 'ar',
            'plan' => 'starter',
            'status' => 'active',
            'feature_flags' => [],
            'tax_rate' => 0,
            'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
            'service_charge_rate' => 0,
            'service_charge_applies_to' => ['dine_in'],
        ];
    }

    public function pro(): self
    {
        return $this->state(['plan' => 'pro']);
    }

    public function enterprise(): self
    {
        return $this->state(['plan' => 'enterprise']);
    }

    public function suspended(): self
    {
        return $this->state(['status' => 'suspended']);
    }
}
