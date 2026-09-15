<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryStock>
 */
class InventoryStockFactory extends Factory
{
    protected $model = InventoryStock::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'current_stock' => fake()->randomFloat(3, 1, 100),
            'reorder_level' => 5,
            'unit_cost' => fake()->randomFloat(4, 1, 50),
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (InventoryStock $stock): void {
            if ($stock->ingredient_id) {
                return;
            }

            $ingredient = Ingredient::factory()->create([
                'tenant_id' => $stock->tenant_id,
                'default_cost' => $stock->unit_cost ?? 0,
            ]);

            $stock->ingredient_id = $ingredient->id;
        });
    }

    public function lowStock(): self
    {
        return $this->state(fn () => [
            'current_stock' => 2,
            'reorder_level' => 10,
        ]);
    }
}
