<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Models;

use App\Modules\Inventory\Stock\Models\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemPartOption extends Model
{
    protected $fillable = [
        'part_id',
        'menu_item_id',
        'ingredient_id',
        'extra_price',
        'price_per_unit',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:2',
            'price_per_unit' => 'decimal:4',
            'is_available' => 'boolean',
        ];
    }

    /** @return BelongsTo<MenuItemPart, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(MenuItemPart::class, 'part_id');
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function isSellable(): bool
    {
        if (! $this->is_available) {
            return false;
        }

        if ($this->menu_item_id !== null) {
            return $this->menuItem?->is_available === true;
        }

        if ($this->ingredient_id !== null) {
            return $this->ingredient?->is_active === true;
        }

        return false;
    }
}
