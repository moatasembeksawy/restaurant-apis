<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        $unit = fake()->randomElement(['kg', 'g', 'l', 'ml', 'piece']);
        $nameEn = fake()->unique()->word();

        return [
            'tenant_id' => Tenant::factory(),
            'sku' => strtoupper(Str::slug($nameEn, '')).'-'.strtoupper($unit).'-'.fake()->unique()->numerify('##'),
            'name_ar' => 'مكون '.$nameEn,
            'name_en' => $nameEn,
            'unit' => $unit,
            'default_cost' => fake()->randomFloat(4, 1, 50),
            'is_active' => true,
        ];
    }
}
