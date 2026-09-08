<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenant\Districts\Models\District;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->city(),
            'delivery_fee' => fake()->randomElement([10, 15, 20, 25, 30]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
