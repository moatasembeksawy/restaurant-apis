<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuPackage>
 */
class MenuPackageFactory extends Factory
{
    protected $model = MenuPackage::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'category_id' => MenuCategory::factory(),
            'name_ar' => 'وجبة '.fake()->word(),
            'name_en' => fake()->words(2, true).' meal',
            'price' => fake()->randomFloat(2, 80, 400),
            'is_available' => true,
            'preparation_time' => fake()->numberBetween(10, 40),
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }
}
