<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Models;

use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Printer extends BaseModel
{
    public const TYPES = ['kitchen', 'receipt', 'both'];

    public const PAPER_WIDTHS = [58, 80];

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'bridge_key',
        'type',
        'paper_width',
        'copies',
        'auto_print',
        'is_default_kitchen',
        'is_default_receipt',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'paper_width' => 'integer',
            'copies' => 'integer',
            'auto_print' => 'boolean',
            'is_default_kitchen' => 'boolean',
            'is_default_receipt' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<KitchenStation, $this> */
    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(KitchenStation::class, 'kitchen_station_printer')
            ->withTimestamps();
    }

    /** @return HasMany<PrintRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(PrintRoute::class);
    }
}
