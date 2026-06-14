<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'phone' => '+2010'.fake()->numerify('########'),
            'name' => fake()->name(),
            'default_address' => fake()->address(),
            'loyalty_points' => 0,
            'visit_count' => 0,
            'total_spent' => 0,
        ];
    }
}
