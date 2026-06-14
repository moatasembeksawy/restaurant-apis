<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->city().' Branch',
            'name_ar' => 'فرع '.fake()->city(),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'is_default' => true,
            'timezone' => 'Africa/Cairo',
            'is_active' => true,
        ];
    }
}
