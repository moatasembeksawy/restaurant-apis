<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('lists customers for the tenant', function (): void {
    Customer::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    Customer::factory()->count(2)->create(); // other tenant

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/customers')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

it('creates a customer by phone', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/customers', [
            'phone' => '01098765432',
            'name' => 'محمود',
            'default_address' => 'القاهرة، مصر',
        ])
        ->assertCreated()
        ->assertJsonPath('data.phone', '+201098765432')
        ->assertJsonPath('data.name', 'محمود');
});

it('shows customer order history', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    Order::factory()->count(2)->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'channel' => 'qr',
    ]);

    $this->withToken($this->token)
        ->getJson("/api/v1/customers/{$customer->id}/orders")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
