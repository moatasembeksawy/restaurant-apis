<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Models;

use App\Shared\Domain\Models\BaseModel;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return CustomerFactory::new();
    }
    protected $fillable = [
        'tenant_id',
        'phone',
        'name',
        'default_address',
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
