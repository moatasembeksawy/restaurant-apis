<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\InventoryStockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $sku
 * @property string|null $name_ar
 * @property string|null $name_en
 * @property string|null $unit
 */
class InventoryStock extends BaseModel
{
    use HasFactory;

    /** @var list<string> */
    protected $with = ['ingredient'];

    /** @var list<string> */
    protected $hidden = ['ingredient'];

    /** @var list<string> */
    protected $appends = ['sku', 'name_ar', 'name_en', 'unit'];

    protected static function newFactory(): Factory
    {
        return InventoryStockFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'ingredient_id',
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

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'ingredient_id', 'ingredient_id');
    }

    public function isLowStock(): bool
    {
        return $this->reorder_level > 0 && $this->current_stock <= $this->reorder_level;
    }

    /** @param Builder<self> $query */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query
            ->select('inventory_stocks.*')
            ->join('ingredients', 'ingredients.id', '=', 'inventory_stocks.ingredient_id')
            ->orderBy('ingredients.name_ar');
    }

    private function master(): ?Ingredient
    {
        $ingredient = $this->ingredient;

        return $ingredient instanceof Ingredient ? $ingredient : null;
    }

    protected function sku(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->master()?->sku);
    }

    protected function nameAr(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->master()?->name_ar);
    }

    protected function nameEn(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->master()?->name_en);
    }

    protected function unit(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->master()?->unit);
    }
}
