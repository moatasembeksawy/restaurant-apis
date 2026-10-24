<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Models;

use App\Modules\Inventory\Stock\Models\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItemPart extends Model
{
    public const TYPE_FIXED = 'fixed';

    public const TYPE_CHOICE = 'choice';

    public const TYPE_SWAP = 'swap';

    public const TYPE_WEIGHT = 'weight';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_FIXED,
        self::TYPE_CHOICE,
        self::TYPE_SWAP,
        self::TYPE_WEIGHT,
    ];

    /** @var list<string> */
    public const WEIGHT_UNITS = ['g', 'kg'];

    protected $fillable = [
        'menu_item_id',
        'type',
        'name_ar',
        'name_en',
        'default_menu_item_id',
        'ingredient_id',
        'quantity',
        'included_quantity',
        'unit',
        'price_per_unit',
        'min_select',
        'max_select',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'included_quantity' => 'decimal:4',
            'price_per_unit' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function defaultMenuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'default_menu_item_id');
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return HasMany<MenuItemPartOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(MenuItemPartOption::class, 'part_id')->orderBy('sort_order');
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function isChoice(): bool
    {
        return $this->type === self::TYPE_CHOICE;
    }

    public function isSwap(): bool
    {
        return $this->type === self::TYPE_SWAP;
    }

    public function isWeight(): bool
    {
        return $this->type === self::TYPE_WEIGHT;
    }

    public function isSatisfiable(): bool
    {
        if ($this->isFixed()) {
            return $this->defaultMenuItem?->is_available === true;
        }

        if ($this->isWeight()) {
            if ($this->ingredient?->is_active === true) {
                return true;
            }

            return $this->options
                ->contains(fn (MenuItemPartOption $option): bool => $option->isSellable());
        }

        if ($this->isSwap() && $this->defaultMenuItem?->is_available === true) {
            return true;
        }

        $available = $this->options
            ->filter(fn (MenuItemPartOption $option): bool => $option->isSellable())
            ->count();

        $required = $this->isSwap() ? 1 : (int) $this->min_select;

        return $available >= $required;
    }
}
