<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Recipes\Models;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recipe extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'menu_item_id',
        'ingredient_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function lineCost(): float
    {
        return round((float) $this->quantity * (float) $this->ingredient->unit_cost, 4);
    }
}
