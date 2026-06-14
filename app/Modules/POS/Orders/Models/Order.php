<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Models;

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return OrderFactory::new();
    }
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'floor_table_id',
        'waiter_id',
        'customer_id',
        'rider_id',
        'channel',
        'status',
        'delivery_status',
        'external_ref',
        'notes',
        'delivery_address',
        'subtotal',
        'discount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    // ── Status transitions ─────────────────────────────────────────────────────

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = [
            'pending' => ['active', 'cancelled'],
            'active' => ['cooking', 'ready', 'completed', 'cancelled'],
            'cooking' => ['ready', 'cancelled'],
            'ready' => ['completed', 'cancelled'],
            'completed' => ['paid'],
            'paid' => [],
            'cancelled' => [],
        ];

        return in_array($newStatus, $allowed[$this->status] ?? []);
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('subtotal');
        $this->total = max(0, $this->subtotal - $this->discount);
        $this->saveQuietly();
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(FloorTable::class, 'floor_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(\App\Modules\POS\Billing\Models\Payment::class);
    }
}
