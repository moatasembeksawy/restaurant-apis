<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\Customers\Models\CustomerAddress;
use App\Modules\Tenant\Districts\Models\District;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => Customer::factory(),
            'district_id' => District::factory(),
            'label' => fake()->optional()->randomElement(['المنزل', 'العمل']),
            'address' => fake()->address(),
            'is_default' => false,
        ];
    }
}
