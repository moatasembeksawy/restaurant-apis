<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offline\Models\OfflineSyncOperation;
use App\Modules\POS\Offline\Support\OfflineOperations;
use App\Modules\POS\Orders\Events\OrderPlaced;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Districts\Models\District;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'pro',
        'status' => 'active',
        'tax_rate' => 14,
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
        'branch_id' => $this->branch->id,
    ]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 50,
        'is_available' => true,
    ]);
    $this->table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->waiter
        ->createToken('offline-test', ['orders:create', 'menu:read', 'tables:read'])
        ->plainTextToken;

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    $this->cashierToken = $this->cashier
        ->createToken('offline-cashier', ['orders:create', 'orders:update', 'billing:*'])
        ->plainTextToken;

    Event::fake([OrderPlaced::class]);
    Queue::fake();
});

it('returns a branch snapshot for offline storage', function (): void {
    $this->withToken($this->token)
        ->getJson("/api/v1/offline/bootstrap?branch_id={$this->branch->id}")
        ->assertOk()
        ->assertJsonPath('data.schema_version', OfflineOperations::SCHEMA_VERSION)
        ->assertJsonPath('data.supported_operations.0', OfflineOperations::ORDER_CREATED)
        ->assertJsonPath('data.branch.id', $this->branch->id)
        ->assertJsonPath('data.categories.0.id', $this->category->id)
        ->assertJsonPath('data.menu_items.0.id', $this->menuItem->id)
        ->assertJsonPath('data.tables.0.id', $this->table->id)
        ->assertJsonStructure([
            'data' => [
                'cursor',
                'packages',
                'offers',
                'districts',
                'staff',
                'printing' => ['printers', 'stations', 'routes'],
                'open_orders',
                'current_shift',
                'business_day',
                'lan_hub' => ['hub_id', 'port', 'discovery_host', 'urls', 'health_path'],
            ],
        ]);
});

it('synchronizes an offline order idempotently', function (): void {
    $operationId = (string) Str::uuid();
    $clientOrderId = (string) Str::uuid();
    $deviceId = (string) Str::uuid();
    $occurredAt = now()->subMinutes(5)->toISOString();

    $payload = [
        'branch_id' => $this->branch->id,
        'device_id' => $deviceId,
        'operations' => [[
            'operation_id' => $operationId,
            'type' => 'order.created',
            'occurred_at' => $occurredAt,
            'payload' => [
                'client_order_id' => $clientOrderId,
                'floor_table_id' => $this->table->id,
                'channel' => 'dine_in',
                'items' => [[
                    'menu_item_id' => $this->menuItem->id,
                    'quantity' => 2,
                ]],
            ],
        ]],
    ];

    $first = $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', $payload)
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'applied')
        ->assertJsonPath('data.results.0.client_order_id', $clientOrderId)
        ->assertJsonPath('data.results.0.total', 114)
        ->assertJsonPath('meta.applied', 1);

    $serverOrderId = $first->json('data.results.0.server_order_id');

    $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', $payload)
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'duplicate')
        ->assertJsonPath('data.results.0.server_order_id', $serverOrderId)
        ->assertJsonPath('meta.duplicates', 1);

    expect(Order::query()->count())->toBe(1)
        ->and(OfflineSyncOperation::query()->count())->toBe(1);

    $order = Order::query()->firstOrFail();
    expect($order->client_order_id)->toBe($clientOrderId)
        ->and($order->source_device_id)->toBe($deviceId)
        ->and($order->offline_created_at)->not->toBeNull()
        ->and($this->table->fresh()->status)->toBe('occupied');
});

it('rejects a bad operation without blocking valid operations in the batch', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => 'order.created',
                    'occurred_at' => now()->subMinute()->toISOString(),
                    'payload' => [
                        'client_order_id' => (string) Str::uuid(),
                        'channel' => 'dine_in',
                        'items' => [[
                            'menu_item_id' => 999_999,
                            'quantity' => 1,
                        ]],
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => 'order.created',
                    'occurred_at' => now()->subMinute()->toISOString(),
                    'payload' => [
                        'client_order_id' => (string) Str::uuid(),
                        'channel' => 'dine_in',
                        'items' => [[
                            'menu_item_id' => $this->menuItem->id,
                            'quantity' => 1,
                        ]],
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'rejected')
        ->assertJsonPath('data.results.0.error.code', 'ORDER_VALIDATION_FAILED')
        ->assertJsonPath('data.results.1.disposition', 'applied')
        ->assertJsonPath('meta.rejected', 1)
        ->assertJsonPath('meta.applied', 1);

    expect($response->json('data.results'))->toHaveCount(2)
        ->and(Order::query()->count())->toBe(1);
});

it('keeps the sold price when the catalog price later changes', function (): void {
    $clientOrderId = (string) Str::uuid();

    $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [[
                'operation_id' => (string) Str::uuid(),
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->subMinute()->toISOString(),
                'payload' => [
                    'client_order_id' => $clientOrderId,
                    'channel' => 'dine_in',
                    'items' => [[
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 2,
                        'unit_price' => 40,
                    ]],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'applied')
        ->assertJsonPath('data.results.0.warnings.0.code', 'PRICE_CHANGED');

    $order = Order::query()->where('client_order_id', $clientOrderId)->firstOrFail();
    expect((float) $order->items()->first()->unit_price)->toBe(40.0)
        ->and((float) $order->subtotal)->toBe(80.0);
});

it('synchronizes item, status, and cash payment operations', function (): void {
    startCashierShift($this->cashier);
    $clientOrderId = (string) Str::uuid();
    $firstItemId = (string) Str::uuid();
    $secondItemId = (string) Str::uuid();
    $paymentId = (string) Str::uuid();
    $deviceId = (string) Str::uuid();

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => $deviceId,
            'operations' => [
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::ORDER_CREATED,
                    'occurred_at' => now()->subMinutes(4)->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'channel' => 'dine_in',
                        'fulfillment_type' => 'takeaway',
                        'items' => [[
                            'client_item_id' => $firstItemId,
                            'menu_item_id' => $this->menuItem->id,
                            'quantity' => 1,
                        ]],
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::ITEM_ADDED,
                    'occurred_at' => now()->subMinutes(3)->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'client_item_id' => $secondItemId,
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::ITEM_UPDATED,
                    'occurred_at' => now()->subMinutes(2)->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'client_item_id' => $secondItemId,
                        'quantity' => 2,
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::STATUS_CHANGED,
                    'occurred_at' => now()->subMinute()->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'status' => 'completed',
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::PAYMENT_RECORDED,
                    'occurred_at' => now()->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'client_payment_id' => $paymentId,
                        'method' => 'cash',
                        'amount' => 171,
                        'cash_tendered' => 200,
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('meta.applied', 5)
        ->assertJsonPath('data.results.4.disposition', 'applied');

    $order = Order::query()->where('client_order_id', $clientOrderId)->firstOrFail();
    expect($order->status)->toBe('paid')
        ->and($order->items()->whereNull('parent_id')->count())->toBe(2)
        ->and((float) $order->subtotal)->toBe(150.0)
        ->and(Payment::query()->where('client_payment_id', $paymentId)->exists())->toBeTrue()
        ->and(OrderItem::query()->where('client_item_id', $secondItemId)->first()->quantity)->toBe(2);
});

it('returns catalog changes after the snapshot cursor', function (): void {
    $cursor = $this->withToken($this->token)
        ->getJson("/api/v1/offline/bootstrap?branch_id={$this->branch->id}")
        ->assertOk()
        ->json('data.cursor');

    $this->menuItem->update(['price' => 75]);

    $this->withToken($this->token)
        ->getJson("/api/v1/offline/changes?branch_id={$this->branch->id}&cursor={$cursor}")
        ->assertOk()
        ->assertJsonPath('data.schema_version', OfflineOperations::SCHEMA_VERSION)
        ->assertJsonPath('data.menu_items.0.id', $this->menuItem->id)
        ->assertJsonPath('data.menu_items.0.price', 75);
});

it('synchronizes kitchen item-ready from the LAN hub', function (): void {
    $clientOrderId = (string) Str::uuid();
    $clientItemId = (string) Str::uuid();

    $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::ORDER_CREATED,
                    'occurred_at' => now()->subMinute()->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'channel' => 'dine_in',
                        'items' => [[
                            'client_item_id' => $clientItemId,
                            'menu_item_id' => $this->menuItem->id,
                            'quantity' => 1,
                        ]],
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::ITEM_READY,
                    'occurred_at' => now()->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'client_item_id' => $clientItemId,
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('meta.applied', 2)
        ->assertJsonPath('data.results.1.disposition', 'applied');

    $order = Order::query()->where('client_order_id', $clientOrderId)->firstOrFail();
    $item = OrderItem::query()->where('client_item_id', $clientItemId)->firstOrFail();

    expect($item->status)->toBe('ready')
        ->and($order->status)->toBe('ready');
});

it('creates a customer from phone and name on an offline delivery order', function (): void {
    $district = District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'delivery_fee' => 20,
        'is_active' => true,
    ]);
    $clientOrderId = (string) Str::uuid();

    $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [[
                'operation_id' => (string) Str::uuid(),
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->subMinute()->toISOString(),
                'payload' => [
                    'client_order_id' => $clientOrderId,
                    'channel' => 'own_delivery',
                    'fulfillment_type' => 'delivery',
                    'customer_name' => 'أحمد محمود',
                    'customer_phone' => '01012345678',
                    'delivery_address' => 'شارع التحرير، الدقي',
                    'district_id' => $district->id,
                    'items' => [[
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                    ]],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'applied');

    $order = Order::query()->where('client_order_id', $clientOrderId)->firstOrFail();
    $customer = Customer::query()->where('phone', '+201012345678')->firstOrFail();

    expect($order->fulfillment_type)->toBe('delivery')
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->delivery_address)->toBe('شارع التحرير، الدقي')
        ->and($order->district_id)->toBe($district->id)
        ->and((float) $order->delivery_fee)->toBe(20.0)
        ->and($customer->name)->toBe('أحمد محمود')
        ->and($customer->default_address)->toBe('شارع التحرير، الدقي');
});

it('reuses an existing customer when the same phone is synced again', function (): void {
    $existing = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '+201055566677',
        'name' => 'سارة',
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [[
                'operation_id' => (string) Str::uuid(),
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->subMinute()->toISOString(),
                'payload' => [
                    'client_order_id' => (string) Str::uuid(),
                    'channel' => 'own_delivery',
                    'fulfillment_type' => 'delivery',
                    'customer_phone' => '01055566677',
                    'customer_name' => 'سارة علي',
                    'delivery_address' => 'مدينة نصر',
                    'items' => [[
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                    ]],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'applied');

    expect(Customer::query()->count())->toBe(1)
        ->and(Order::query()->firstOrFail()->customer_id)->toBe($existing->id)
        ->and($existing->fresh()->name)->toBe('سارة');
});

it('places an offline delivery order without a customer when only an address is given', function (): void {
    $clientOrderId = (string) Str::uuid();

    $this->withToken($this->token)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [[
                'operation_id' => (string) Str::uuid(),
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->subMinute()->toISOString(),
                'payload' => [
                    'client_order_id' => $clientOrderId,
                    'channel' => 'own_delivery',
                    'fulfillment_type' => 'delivery',
                    'delivery_address' => 'Guest drop-off at hotel lobby',
                    'items' => [[
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                    ]],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.results.0.disposition', 'applied');

    $order = Order::query()->where('client_order_id', $clientOrderId)->firstOrFail();

    expect($order->customer_id)->toBeNull()
        ->and($order->fulfillment_type)->toBe('delivery')
        ->and($order->delivery_address)->toBe('Guest drop-off at hotel lobby')
        ->and(Customer::query()->count())->toBe(0);
});

it('rejects an offline card payment', function (): void {
    $clientOrderId = (string) Str::uuid();

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/offline/sync', [
            'branch_id' => $this->branch->id,
            'device_id' => (string) Str::uuid(),
            'operations' => [
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::ORDER_CREATED,
                    'occurred_at' => now()->subMinute()->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'channel' => 'dine_in',
                        'items' => [[
                            'menu_item_id' => $this->menuItem->id,
                            'quantity' => 1,
                        ]],
                    ],
                ],
                [
                    'operation_id' => (string) Str::uuid(),
                    'type' => OfflineOperations::PAYMENT_RECORDED,
                    'occurred_at' => now()->toISOString(),
                    'payload' => [
                        'client_order_id' => $clientOrderId,
                        'client_payment_id' => (string) Str::uuid(),
                        'method' => 'card',
                        'amount' => 57,
                    ],
                ],
            ],
        ])
        ->assertUnprocessable();
});
