<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Models;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'supplier_id',
        'created_by',
        'received_by_id',
        'completed_by_id',
        'status',
        'total',
        'received_total',
        'notes',
        'ordered_at',
        'received_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'received_total' => 'decimal:2',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function recalculateTotal(): void
    {
        $this->total = $this->items()->sum('subtotal');
        $this->saveQuietly();
    }
}
