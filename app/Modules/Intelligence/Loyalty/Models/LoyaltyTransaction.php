<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Loyalty\Models;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Domain\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'order_id',
        'type',
        'points',
        'balance_after',
        'monetary_value',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'monetary_value' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
