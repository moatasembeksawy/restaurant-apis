<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Models\Supplier;
use App\Modules\Inventory\Stock\Models\PurchaseOrder;
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

    $this->ingredient = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
        'unit_cost' => 20,
    ]);

    Recipe::create([
        'tenant_id' => $this->tenant->id,
        'menu_item_id' => $this->menuItem->id,
        'ingredient_id' => $this->ingredient->id,
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

it('receives purchase order and increases stock', function (): void {
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
                ['ingredient_id' => $this->ingredient->id, 'quantity' => 5, 'unit_cost' => 22],
            ],
        ]);

    $response->assertCreated();
    $poId = $response->json('data.id');

    $this->withToken($managerToken)
        ->postJson("/api/v1/inventory/purchase-orders/{$poId}/receive")
        ->assertOk();

    expect((float) $this->ingredient->fresh()->current_stock)->toBe(15.0);
    expect(PurchaseOrder::find($poId)->status)->toBe('received');
});
