<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Models;

use App\Models\User;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentRefund;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffShift extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'clock_in',
        'clock_out',
        'notes',
        'opening_float',
        'closing_cash_count',
        'expected_cash',
        'cash_variance',
    ];

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function isActive(): bool
    {
        return $this->clock_out === null;
    }
}
