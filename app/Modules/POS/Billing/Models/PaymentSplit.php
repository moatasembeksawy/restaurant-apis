<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSplit extends Model
{
    protected $fillable = [
        'payment_id',
        'method',
        'amount',
        'reference',
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
}
