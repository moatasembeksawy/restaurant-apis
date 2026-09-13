<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Shared\Domain\Models\BaseModel;
use Database\Factories\IngredientCatalogFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngredientCatalog extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return IngredientCatalogFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'sku',
        'name_ar',
        'name_en',
        'unit',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'catalog_id');
    }
}
