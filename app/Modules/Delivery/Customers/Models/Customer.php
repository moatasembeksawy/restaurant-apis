<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Models;

use App\Shared\Domain\Models\BaseModel;

class Customer extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'phone',
        'name',
        'loyalty_points',
        'visit_count',
        'total_spent',
        'last_order_at',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'integer',
            'visit_count' => 'integer',
            'total_spent' => 'decimal:2',
            'last_order_at' => 'datetime',
        ];
    }
}
