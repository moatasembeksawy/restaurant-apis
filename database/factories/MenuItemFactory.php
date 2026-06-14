<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 15, 250);

        return [
            'tenant_id' => Tenant::factory(),
            'category_id' => MenuCategory::factory(),
            'name_ar' => 'طبق '.fake()->word(),
            'name_en' => fake()->words(2, true),
            'price' => $price,
            'cost_price' => round($price * 0.4, 2),
            'is_available' => true,
            'preparation_time' => fake()->numberBetween(5, 30),
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }

    public function unavailable(): self
    {
        return $this->state(['is_available' => false]);
    }
}
