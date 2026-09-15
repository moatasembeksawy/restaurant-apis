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
