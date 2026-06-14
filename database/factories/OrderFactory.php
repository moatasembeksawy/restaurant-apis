<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'floor_table_id' => null,
            'waiter_id' => null,
            'customer_id' => null,
            'channel' => fake()->randomElement(['dine_in', 'qr', 'whatsapp']),
            'status' => 'active',
            'subtotal' => 100.00,
            'discount' => 0.00,
            'total' => 100.00,
        ];
    }

    public function paid(): self
    {
        return $this->state(['status' => 'paid']);
    }

    public function cancelled(): self
    {
        return $this->state(['status' => 'cancelled']);
    }
}
