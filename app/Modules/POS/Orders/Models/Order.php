<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Models;

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Support\OrderCharges;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
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
        'fulfillment_type',
        'status',
        'delivery_status',
        'external_ref',
        'notes',
        'delivery_address',
        'subtotal',
        'discount',
        'delivery_fee',
        'tax_rate',
        'tax',
        'service_charge_rate',
        'service_charge',
        'service_charge_applies_to',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'service_charge_applies_to' => 'array',
            'total' => 'decimal:2',
        ];
    }

    // ── Status transitions ─────────────────────────────────────────────────────

    public function canEdit(): bool
    {
        return in_array($this->status, ['pending', 'active', 'cooking', 'ready'], true);
    }

    public function canAddItems(): bool
    {
        return $this->canEdit();
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = [
            'pending' => ['active', 'cancelled'],
            'active' => ['cooking', 'ready', 'completed', 'cancelled'],
            'cooking' => ['ready', 'cancelled'],
            'ready' => ['cooking', 'completed', 'cancelled'],
            'completed' => ['paid'],
            'paid' => ['refunded'],
            'refunded' => [],
            'cancelled' => [],
        ];

        return in_array($newStatus, $allowed[$this->status], true);
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('subtotal');
        $charges = OrderCharges::compute($this);
        $this->service_charge = $charges['service_charge'];
        $this->tax = $charges['tax'];
        $this->total = $charges['total'];
        $this->saveQuietly();
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<FloorTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(FloorTable::class, 'floor_table_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
        return $this->hasOne(Payment::class);
    }

    public function isDelivery(): bool
    {
        return $this->fulfillment_type === 'delivery';
    }
}
