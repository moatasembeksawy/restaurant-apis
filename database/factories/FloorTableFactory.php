<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FloorTable>
 */
class FloorTableFactory extends Factory
{
    protected $model = FloorTable::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'name' => 'T'.fake()->unique()->numberBetween(1, 99),
            'section' => fake()->randomElement(['Main Hall', 'VIP', 'Outdoor', 'Terrace']),
            'capacity' => fake()->randomElement([2, 4, 6, 8]),
            'status' => 'free',
            'position_x' => fake()->numberBetween(0, 10),
            'position_y' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    public function occupied(): self
    {
        return $this->state(['status' => 'occupied']);
    }
}
