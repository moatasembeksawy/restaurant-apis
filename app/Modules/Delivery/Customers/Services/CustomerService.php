<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Services;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Orders\Models\Order;

class CustomerService
{
    public function findOrCreate(string $phone, ?string $name = null, ?string $address = null): Customer
    {
        $normalized = $this->normalizePhone($phone);

        $customer = Customer::query()->firstOrCreate(
            ['phone' => $normalized],
            [
                'name' => $name,
                'default_address' => $address,
            ],
        );

        if ($name && ! $customer->name) {
            $customer->update(['name' => $name]);
        }

        if ($address && ! $customer->default_address) {
            $customer->update(['default_address' => $address]);
        }

        return $customer->fresh();
    }

    public function recordOrder(Customer $customer, Order $order): void
    {
        $customer->update([
            'visit_count' => $customer->visit_count + 1,
            'total_spent' => $customer->total_spent + $order->total,
            'last_order_at' => now(),
        ]);
    }

    public function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $phone) ?? $phone;

        if (str_starts_with($cleaned, '01') && strlen($cleaned) === 11) {
            return '+2'.$cleaned;
        }

        if (str_starts_with($cleaned, '201')) {
            return '+'.$cleaned;
        }

        return $cleaned;
    }
}
