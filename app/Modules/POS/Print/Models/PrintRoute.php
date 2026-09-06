<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Models;

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintRoute extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'menu_category_id',
        'menu_item_id',
        'printer_id',
        'kitchen_station_id',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }
}
