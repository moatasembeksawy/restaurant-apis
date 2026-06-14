<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'name_ar' => 'مكون '.fake()->word(),
            'name_en' => fake()->word(),
            'unit' => fake()->randomElement(['kg', 'g', 'l', 'ml', 'piece']),
            'current_stock' => fake()->randomFloat(3, 1, 100),
            'reorder_level' => 5,
            'unit_cost' => fake()->randomFloat(4, 1, 50),
            'is_active' => true,
        ];
    }

    public function lowStock(): self
    {
        return $this->state(fn () => [
            'current_stock' => 2,
            'reorder_level' => 10,
        ]);
    }
}
