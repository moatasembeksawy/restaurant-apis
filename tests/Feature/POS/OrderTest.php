<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Events\OrderPlaced;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Event;

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
    expect(Order::query()->latest('id')->first()->fulfillment_type)->toBe('dine_in');
});

it('places an order even when the kitchen websocket is down', function (): void {
    Event::listen(OrderPlaced::class, function (): void {
        throw new RuntimeException('Pusher error: cURL error 7: Failed to connect to localhost:8080');
    });

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'floor_table_id' => $this->table->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    expect(Order::query()->count())->toBe(1);
});

it('attaches a customer when placing an order', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'floor_table_id' => $this->table->id,
            'channel' => 'dine_in',
            'customer_id' => $customer->id,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.customer_id', $customer->id);
});

it('rejects a missing customer_id instead of failing on insert', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'customer_id' => 999_999,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.0.field', 'customer_id');
});

it('rejects a customer from another tenant', function (): void {
    $foreignCustomer = Customer::factory()->create();

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'customer_id' => $foreignCustomer->id,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
});

it('creates a takeaway order when staff sets fulfillment_type', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'fulfillment_type' => 'takeaway',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.fulfillment_type', 'takeaway');
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

it('allows adding items to a ready order and reopens it for the kitchen', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'waiter_id' => $this->waiter->id,
        'status' => 'ready',
        'subtotal' => 50.00,
        'total' => 50.00,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$order->id}/items", [
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
            'notes' => 'extra drink',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    expect($order->fresh()->status)->toBe('cooking');
    expect((float) $order->fresh()->total)->toBe(50.0);
});

it('allows adding items to a cooking order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'waiter_id' => $this->waiter->id,
        'status' => 'cooking',
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$order->id}/items", [
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    expect($order->fresh()->status)->toBe('cooking');
});

it('rejects adding items to a paid order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$order->id}/items", [
            'menu_item_id' => $this->menuItem->id,
            'quantity' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_NOT_EDITABLE');
});

it('allows removing a pending late-add from a cooking order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'cooking',
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 50.00,
        'quantity' => 1,
        'subtotal' => 50.00,
        'status' => 'pending',
    ]);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/orders/{$order->id}/items/{$item->id}")
        ->assertNoContent();
});

it('rejects removing a cooked item from a ready order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'ready',
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 50.00,
        'quantity' => 1,
        'subtotal' => 50.00,
        'status' => 'ready',
    ]);

    $this->withToken($this->token)
        ->deleteJson("/api/v1/orders/{$order->id}/items/{$item->id}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ITEM_NOT_EDITABLE');
});

it('updates an order channel, table, and notes', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'floor_table_id' => $this->table->id,
        'channel' => 'dine_in',
        'fulfillment_type' => 'dine_in',
        'status' => 'active',
    ]);
    $this->table->update(['status' => 'occupied']);

    $otherTable = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'channel' => 'qr',
            'floor_table_id' => $otherTable->id,
            'notes' => 'Moved to window',
        ])
        ->assertOk()
        ->assertJsonPath('data.channel', 'qr')
        ->assertJsonPath('data.floor_table_id', $otherTable->id)
        ->assertJsonPath('data.fulfillment_type', 'dine_in')
        ->assertJsonPath('data.notes', 'Moved to window');

    expect($this->table->fresh()->status)->toBe('free');
    expect($otherTable->fresh()->status)->toBe('occupied');
});

it('converts a dine-in order to takeaway and frees the table', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'floor_table_id' => $this->table->id,
        'channel' => 'dine_in',
        'fulfillment_type' => 'dine_in',
        'status' => 'active',
    ]);
    $this->table->update(['status' => 'occupied']);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'fulfillment_type' => 'takeaway',
        ])
        ->assertOk()
        ->assertJsonPath('data.fulfillment_type', 'takeaway')
        ->assertJsonPath('data.floor_table_id', null);

    expect($this->table->fresh()->status)->toBe('free');
});

it('converts an order to delivery when an address is provided', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'floor_table_id' => $this->table->id,
        'channel' => 'dine_in',
        'fulfillment_type' => 'dine_in',
        'status' => 'cooking',
    ]);
    $this->table->update(['status' => 'occupied']);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'channel' => 'own_delivery',
            'fulfillment_type' => 'delivery',
            'delivery_address' => 'Nasr City',
            'delivery_fee' => 20,
        ])
        ->assertOk()
        ->assertJsonPath('data.channel', 'own_delivery')
        ->assertJsonPath('data.fulfillment_type', 'delivery')
        ->assertJsonPath('data.delivery_address', 'Nasr City')
        ->assertJsonPath('data.delivery_status', 'pending')
        ->assertJsonPath('data.floor_table_id', null);

    expect((float) $order->fresh()->delivery_fee)->toBe(20.0);
    expect($this->table->fresh()->status)->toBe('free');
});

it('includes delivery_fee in the order total', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'own_delivery',
            'fulfillment_type' => 'delivery',
            'delivery_address' => 'Nasr City',
            'delivery_fee' => 15.50,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 2],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.fulfillment_type', 'delivery');

    expect((float) $response->json('data.delivery_fee'))->toBe(15.5);
    expect((float) $response->json('data.subtotal'))->toBe(100.0);
    expect((float) $response->json('data.total'))->toBe(115.5);
});

it('updates delivery_fee on an open delivery order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_address' => 'Nasr City',
        'delivery_fee' => 10.00,
        'subtotal' => 50.00,
        'total' => 60.00,
        'status' => 'active',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 50.00,
        'quantity' => 1,
        'subtotal' => 50.00,
        'status' => 'pending',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'delivery_fee' => 25,
        ])
        ->assertOk();

    expect((float) $order->fresh()->delivery_fee)->toBe(25.0);
    expect((float) $order->fresh()->total)->toBe(75.0);
});

it('clears delivery_fee when converting a delivery order to takeaway', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'own_delivery',
        'fulfillment_type' => 'delivery',
        'delivery_address' => 'Nasr City',
        'delivery_fee' => 20.00,
        'subtotal' => 50.00,
        'total' => 70.00,
        'status' => 'active',
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 50.00,
        'quantity' => 1,
        'subtotal' => 50.00,
        'status' => 'pending',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'fulfillment_type' => 'takeaway',
            'channel' => 'dine_in',
        ])
        ->assertOk()
        ->assertJsonPath('data.fulfillment_type', 'takeaway');

    expect((float) $order->fresh()->delivery_fee)->toBe(0.0);
    expect((float) $order->fresh()->total)->toBe(50.0);
});

it('rejects delivery_fee on a dine-in order', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'floor_table_id' => $this->table->id,
            'channel' => 'dine_in',
            'delivery_fee' => 10,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
});

it('attaches a customer when updating an order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'channel' => 'dine_in',
        'fulfillment_type' => 'takeaway',
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'customer_id' => $customer->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.customer_id', $customer->id);
});

it('rejects delivery updates without an address', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'channel' => 'dine_in',
        'fulfillment_type' => 'takeaway',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'fulfillment_type' => 'delivery',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
});

it('rejects updating a paid order', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'paid',
        'channel' => 'dine_in',
        'fulfillment_type' => 'dine_in',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'notes' => 'too late',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_NOT_EDITABLE');
});

it('rejects a table from another branch', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
        'channel' => 'dine_in',
        'fulfillment_type' => 'takeaway',
    ]);

    $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $foreignTable = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'status' => 'free',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/orders/{$order->id}", [
            'floor_table_id' => $foreignTable->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
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
