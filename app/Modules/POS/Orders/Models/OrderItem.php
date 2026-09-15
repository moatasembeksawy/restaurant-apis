<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Models;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    public const LINE_ITEM = 'item';

    public const LINE_PACKAGE = 'package';

    public const LINE_COMPONENT = 'package_component';

    protected $fillable = [
        'order_id',
        'line_type',
        'menu_item_id',
        'package_id',
        'parent_id',
        'item_name_ar',
        'unit_price',
        'quantity',
        'subtotal',
        'status',
        'notes',
        'selections',
        'cooked_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'selections' => 'array',
            'cooked_at' => 'datetime',
        ];
    }

    public function isPackageParent(): bool
    {
        return $this->line_type === self::LINE_PACKAGE;
    }

    public function isComponent(): bool
    {
        return $this->line_type === self::LINE_COMPONENT;
    }

    public function isKitchenLine(): bool
    {
        return ! $this->isPackageParent() && $this->menu_item_id !== null;
    }

    public function isPricedLine(): bool
    {
        return $this->parent_id === null;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return BelongsTo<MenuPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(MenuPackage::class, 'package_id');
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
