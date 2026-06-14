<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Models;

use App\Models\User;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Support\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'order_id',
        'refunded_by',
        'staff_shift_id',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function staffShift(): BelongsTo
    {
        return $this->belongsTo(StaffShift::class);
    }
}
