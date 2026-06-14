<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuCategory>
 */
class MenuCategoryFactory extends Factory
{
    protected $model = MenuCategory::class;

    public function definition(): array
    {
        $names = ['المقبلات', 'الأطباق الرئيسية', 'المشروبات', 'الحلويات', 'السلطات', 'المشويات'];

        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => null,
            'name_ar' => fake()->randomElement($names).' '.fake()->numberBetween(1, 99),
            'name_en' => fake()->word(),
            'sort_order' => fake()->numberBetween(1, 10),
            'is_visible' => true,
        ];
    }
}
