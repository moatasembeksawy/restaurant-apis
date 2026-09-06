<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Models;

use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenStation extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<Printer, $this> */
    public function printers(): BelongsToMany
    {
        return $this->belongsToMany(Printer::class, 'kitchen_station_printer')
            ->withTimestamps();
    }

    /** @return HasMany<PrintRoute, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(PrintRoute::class);
    }
}
