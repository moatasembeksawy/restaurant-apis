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
        'fulfillment_type' => 'delivery',
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

it('lists active deliveries for staff', function (): void {
    $this->order->update([
        'rider_id' => $this->rider->id,
        'delivery_status' => 'assigned',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/riders/deliveries')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->order->id)
        ->assertJsonPath('data.0.rider_id', $this->rider->id);
});

it('filters staff deliveries by rider', function (): void {
    $otherRider = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'rider',
        'is_active' => true,
    ]);

    $this->order->update([
        'rider_id' => $this->rider->id,
        'delivery_status' => 'assigned',
    ]);

    $otherOrder = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'picked_up',
        'rider_id' => $otherRider->id,
        'status' => 'active',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/riders/deliveries?rider_id='.$this->rider->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->order->id);

    expect(collect($this->withToken($this->token)
        ->getJson('/api/v1/riders/deliveries')
        ->json('data'))->pluck('id'))
        ->toContain($this->order->id, $otherOrder->id);
});

it('does not let a rider see another rider deliveries', function (): void {
    $otherRider = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'rider',
        'is_active' => true,
    ]);

    $this->order->update([
        'rider_id' => $otherRider->id,
        'delivery_status' => 'assigned',
    ]);

    $this->withToken($this->riderToken)
        ->getJson('/api/v1/riders/deliveries?rider_id='.$otherRider->id)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lists unassigned delivery orders', function (): void {
    $assigned = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'assigned',
        'rider_id' => $this->rider->id,
        'status' => 'active',
    ]);

    $delivered = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'delivered',
        'rider_id' => $this->rider->id,
        'status' => 'completed',
    ]);

    $cancelled = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'pending',
        'status' => 'cancelled',
    ]);

    $dineIn = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'dine_in',
        'fulfillment_type' => 'dine_in',
        'delivery_status' => null,
        'status' => 'active',
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/deliveries/unassigned')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->order->id)
        ->assertJsonPath('data.0.delivery_status', 'pending');

    expect($response->json('data.0.rider_id'))->toBeNull();
    expect(collect($response->json('data'))->pluck('id'))
        ->not->toContain($assigned->id, $delivered->id, $cancelled->id, $dineIn->id);
});

it('filters unassigned deliveries by branch', function (): void {
    $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_status' => 'pending',
        'status' => 'active',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/deliveries/unassigned?branch_id='.$this->branch->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->order->id);
});

it('rejects rider assignment for dine-in orders', function (): void {
    $this->order->update([
        'channel' => 'dine_in',
        'fulfillment_type' => 'dine_in',
        'delivery_status' => null,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/assign-rider", [
            'rider_id' => $this->rider->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'DELIVERY_ERROR');
});
