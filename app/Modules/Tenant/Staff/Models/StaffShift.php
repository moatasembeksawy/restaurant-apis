<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Models;

use App\Models\User;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentRefund;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\BusinessDays\Models\BusinessDay;
use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffShift extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'business_day_id',
        'user_id',
        'clock_in',
        'clock_out',
        'notes',
        'opening_float',
        'closing_cash_count',
        'expected_cash',
        'cash_variance',
    ];

    /**
     * @return array{
     *     clock_in: 'datetime',
     *     clock_out: 'datetime',
     *     opening_float: 'decimal:2',
     *     closing_cash_count: 'decimal:2',
     *     expected_cash: 'decimal:2',
     *     cash_variance: 'decimal:2'
     * }
     */
    protected function casts(): array
    {
        return [
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'opening_float' => 'decimal:2',
            'closing_cash_count' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function businessDay(): BelongsTo
    {
        return $this->belongsTo(BusinessDay::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @return HasMany<CashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function isActive(): bool
    {
        return $this->clock_out === null;
    }
}
