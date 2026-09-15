<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Models;

use App\Modules\POS\Menu\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuPackageSlot extends Model
{
    public const TYPE_FIXED = 'fixed';

    public const TYPE_CHOICE = 'choice';

    protected $fillable = [
        'package_id',
        'type',
        'menu_item_id',
        'quantity',
        'name_ar',
        'name_en',
        'min_select',
        'max_select',
        'sort_order',
    ];

    /** @return BelongsTo<MenuPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(MenuPackage::class, 'package_id');
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    /** @return HasMany<MenuPackageSlotOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(MenuPackageSlotOption::class, 'slot_id')->orderBy('sort_order');
    }

    public function isFixed(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    public function isSatisfiable(): bool
    {
        if ($this->isFixed()) {
            return $this->menuItem?->is_available === true;
        }

        $available = $this->options
            ->filter(fn (MenuPackageSlotOption $option): bool => $option->isSellable())
            ->count();

        return $available >= (int) $this->min_select;
    }
}
