<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Models;

use App\Modules\Tenant\Districts\Models\District;
use App\Shared\Domain\Models\BaseModel;
use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends BaseModel
{
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return CustomerAddressFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'district_id',
        'label',
        'address',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<District, $this> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
