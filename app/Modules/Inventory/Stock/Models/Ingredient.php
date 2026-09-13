<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Stock\Services\IngredientCatalogService;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return IngredientFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $ingredient): void {
            if ($ingredient->catalog_id) {
                return;
            }

            $catalog = app(IngredientCatalogService::class)->findOrCreate(
                catalogId: null,
                nameAr: $ingredient->name_ar,
                nameEn: $ingredient->name_en,
                unit: $ingredient->unit,
                tenantId: $ingredient->tenant_id,
            );

            $ingredient->catalog_id = $catalog->id;
        });
    }

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'catalog_id',
        'name_ar',
        'name_en',
        'unit',
        'current_stock',
        'reorder_level',
        'unit_cost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(IngredientCatalog::class, 'catalog_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->reorder_level > 0 && $this->current_stock <= $this->reorder_level;
    }
}
