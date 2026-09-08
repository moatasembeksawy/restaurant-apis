<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Districts\Models;

use App\Modules\Delivery\Customers\Models\CustomerAddress;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\DistrictFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return DistrictFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'delivery_fee',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected $attributes = [
        'delivery_fee' => 0,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
