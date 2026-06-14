<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Models;

use App\Modules\POS\Orders\Models\Order;
use App\Models\User;
use App\Shared\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'cashier_id',
        'method',
        'amount',
        'cash_tendered',
        'change_due',
        'discount_type',
        'discount_value',
        'discount_reason',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cash_tendered' => 'decimal:2',
            'change_due' => 'decimal:2',
            'discount_value' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** @return HasMany<PaymentSplit, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(PaymentSplit::class);
    }
}
