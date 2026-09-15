<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends BaseModel
{
    use HasFactory;

    public const UNITS = ['kg', 'g', 'l', 'ml', 'piece'];

    /** @var array<string, string> */
    public const UNIT_ALIASES = [
        'kilo' => 'kg',
        'kilos' => 'kg',
        'kilogram' => 'kg',
        'kilograms' => 'kg',
        'kgs' => 'kg',
        'كيلو' => 'kg',
        'كجم' => 'kg',
        'كغ' => 'kg',
        'كيلوجرام' => 'kg',
        'كيلوغرام' => 'kg',
        'كيلو جرام' => 'kg',
        'كيلو غرام' => 'kg',
        'gram' => 'g',
        'grams' => 'g',
        'جم' => 'g',
        'جرام' => 'g',
        'غرام' => 'g',
        'liter' => 'l',
        'litre' => 'l',
        'liters' => 'l',
        'litres' => 'l',
        'لتر' => 'l',
        'لترات' => 'l',
        'milliliter' => 'ml',
        'millilitre' => 'ml',
        'ml' => 'ml',
        'مل' => 'ml',
        'مللي' => 'ml',
        'ملل' => 'ml',
        'pieces' => 'piece',
        'pcs' => 'piece',
        'pc' => 'piece',
        'قطعة' => 'piece',
        'قطعه' => 'piece',
        'قطع' => 'piece',
        'حبة' => 'piece',
        'حبه' => 'piece',
        'حبات' => 'piece',
    ];

    public static function canonicalizeUnit(string $unit): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $unit)));

        return self::UNIT_ALIASES[$normalized] ?? $normalized;
    }

    protected static function newFactory(): Factory
    {
        return IngredientFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'sku',
        'name_ar',
        'name_en',
        'unit',
        'default_cost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_cost' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }
}
