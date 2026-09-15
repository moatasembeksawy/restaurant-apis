<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Models;

use App\Modules\POS\Menu\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuPackageSlotOption extends Model
{
    protected $fillable = [
        'slot_id',
        'menu_item_id',
        'extra_price',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    /** @return BelongsTo<MenuPackageSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MenuPackageSlot::class, 'slot_id');
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function isSellable(): bool
    {
        return $this->is_available && $this->menuItem?->is_available === true;
    }
}
