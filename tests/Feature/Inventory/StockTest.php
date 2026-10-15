<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrder;
use App\Modules\Inventory\Suppliers\Models\Supplier;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 100.00,
    ]);

    $this->ingredient = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
        'unit_cost' => 20,
    ]);

    Recipe::create([
        'tenant_id' => $this->tenant->id,
        'menu_item_id' => $this->menuItem->id,
        'ingredient_id' => $this->ingredient->ingredient_id,
        'quantity' => 0.5,
    ]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'ready',
        'total' => 100,
    ]);

    OrderItem::create([
        'order_id' => $this->order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 100,
        'quantity' => 2,
        'subtotal' => 200,
        'status' => 'ready',
    ]);

    app()->instance('tenant', $this->tenant);
    startCashierShift($this->cashier);
    $this->token = $this->cashier->createToken('test')->plainTextToken;
});

it('deducts stock when order is paid', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 100,
        ])
        ->assertOk();

    // 0.5 kg per item × 2 items = 1.0 kg deducted
    expect((float) $this->ingredient->fresh()->current_stock)->toBe(9.0);
    expect(StockMovement::query()->where('type', 'sale')->count())->toBe(1);
});

it('checks a received purchase order before completing it into stock', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $managerToken = $manager->createToken('test')->plainTextToken;

    $supplier = Supplier::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Metro Market',
    ]);

    $response = $this->withToken($managerToken)
        ->postJson('/api/v1/inventory/purchase-orders', [
            'branch_id' => $this->branch->id,
            'supplier_id' => $supplier->id,
            'items' => [
                ['ingredient_id' => $this->ingredient->ingredient_id, 'quantity' => 5, 'unit_cost' => 22],
            ],
        ]);

    $response->assertCreated();
    $poId = $response->json('data.id');
    $itemId = $response->json('data.items.0.id');

    $printResponse = $this->withToken($managerToken)
        ->getJson("/api/v1/inventory/purchase-orders/{$poId}/print")
        ->assertOk()
        ->assertJsonPath('data.format', 'escpos');

    expect(base64_decode($printResponse->json('data.bytes'), true))
        ->toContain('PURCHASE ORDER')
        ->toContain("PO #{$poId}");

    $receivedItems = [
        [
            'purchase_order_item_id' => $itemId,
            'received_quantity' => 4.5,
            'unit_cost' => 23,
        ],
    ];

    $this->withToken($managerToken)
        ->postJson("/api/v1/inventory/purchase-orders/{$poId}/receive", ['items' => $receivedItems])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'INVALID_PO_STATUS');

    $this->withToken($managerToken)
        ->postJson("/api/v1/inventory/purchase-orders/{$poId}/submit")
        ->assertOk();

    $this->withToken($managerToken)
        ->postJson("/api/v1/inventory/purchase-orders/{$poId}/receive", ['items' => $receivedItems])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.received_total', '103.50');

    expect((float) $this->ingredient->fresh()->current_stock)->toBe(10.0);

    $this->withToken($managerToken)
        ->postJson("/api/v1/inventory/purchase-orders/{$poId}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect((float) $this->ingredient->fresh()->current_stock)->toBe(14.5);
    expect(PurchaseOrder::find($poId)->status)->toBe('completed');
    expect((float) StockMovement::query()
        ->where('reference_id', $poId)
        ->where('type', 'purchase')
        ->value('quantity'))->toBe(4.5);
});

it('deducts the destination branch stock row for a shared ingredient', function (): void {
    $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherStock = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'ingredient_id' => $this->ingredient->ingredient_id,
        'current_stock' => 10,
        'unit_cost' => 20,
    ]);

    $otherOrder = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'status' => 'ready',
        'total' => 100,
    ]);

    OrderItem::create([
        'order_id' => $otherOrder->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 100,
        'quantity' => 2,
        'subtotal' => 200,
        'status' => 'ready',
    ]);

    $otherCashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    startCashierShift($otherCashier);

    $this->withToken($otherCashier->createToken('test')->plainTextToken)
        ->postJson("/api/v1/orders/{$otherOrder->id}/pay", [
            'method' => 'cash',
            'amount' => 100,
        ])
        ->assertOk();

    expect((float) $this->ingredient->fresh()->current_stock)->toBe(10.0);
    expect((float) $otherStock->fresh()->current_stock)->toBe(9.0);
});
