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

    $this->rider = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'rider',
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'own_delivery',
        'delivery_status' => 'pending',
        'status' => 'active',
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
    $this->riderToken = $this->rider->createToken('test')->plainTextToken;
});

it('lists active riders', function (): void {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/riders')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.role'))->toBeNull(); // only selected fields
});

it('assigns a rider to a delivery order', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/assign-rider", [
            'rider_id' => $this->rider->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_status', 'assigned');

    expect($this->order->fresh()->rider_id)->toBe($this->rider->id);
});

it('updates delivery status through lifecycle', function (): void {
    $this->order->update(['delivery_status' => 'assigned', 'rider_id' => $this->rider->id]);

    $this->withToken($this->riderToken)
        ->patchJson("/api/v1/orders/{$this->order->id}/delivery-status", [
            'status' => 'picked_up',
        ])
        ->assertOk()
        ->assertJsonPath('data.delivery_status', 'picked_up');
});

it('shows rider active deliveries', function (): void {
    $this->order->update([
        'rider_id' => $this->rider->id,
        'delivery_status' => 'assigned',
    ]);

    $this->withToken($this->riderToken)
        ->getJson('/api/v1/riders/deliveries')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects rider assignment for dine-in orders', function (): void {
    $this->order->update(['channel' => 'dine_in', 'delivery_status' => null]);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/assign-rider", [
            'rider_id' => $this->rider->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'DELIVERY_ERROR');
});
