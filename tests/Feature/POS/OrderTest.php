<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'pro',
        'status' => 'active',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $this->waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->category = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 50.00,
        'is_available' => true,
    ]);

    $this->table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);

    // Bind tenant to app container and use Sanctum token
    app()->instance('tenant', $this->tenant);
    $this->token = $this->waiter->createToken('test', ['orders:create', 'orders:update', 'menu:read', 'tables:*'])->plainTextToken;
});

it('allows a waiter to place an order', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'floor_table_id' => $this->table->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'data' => ['id', 'status', 'total', 'items'],
        ])
        ->assertJsonPath('data.status', 'active');

    // Table should now be occupied
    expect($this->table->fresh()->status)->toBe('occupied');
});

it('calculates order total correctly', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 3],
            ],
        ]);

    $response->assertCreated();
    expect((float) $response->json('data.total'))->toBe(150.0); // 3 × 50
});

it('allows adding items to an active order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'waiter_id' => $this->waiter->id,
        'status' => 'active',
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$order->id}/items", [
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');
});

it('validates order status transitions', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'pending',
    ]);

    // Cannot jump from pending to paid
    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'paid'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'INVALID_STATUS_TRANSITION');
});

it('blocks cross-tenant order access', function (): void {
    $otherTenant = Tenant::factory()->create(['status' => 'active']);
    $otherOrder = Order::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->withToken($this->token)
        ->getJson("/api/v1/orders/{$otherOrder->id}")
        ->assertNotFound();
});
