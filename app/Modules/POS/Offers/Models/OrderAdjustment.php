<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Models;

use App\Modules\POS\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAdjustment extends Model
{
    public const SOURCE_OFFER = 'offer';

    public const SOURCE_COUPON = 'coupon';

    public const SOURCE_LOYALTY = 'loyalty';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'order_id',
        'source',
        'offer_id',
        'amount',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
