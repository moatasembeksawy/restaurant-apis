<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-15 12:00:00');

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 120.00,
    ]);

    $this->beef = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'unit_cost' => 50.00,
    ]);
    $this->rice = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'unit_cost' => 10.00,
    ]);
    $this->beef->ingredient->update(['default_cost' => 50.00]);
    $this->rice->ingredient->update(['default_cost' => 10.00]);

    app()->instance('tenant', $this->tenant);
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $this->putJson("/api/v1/menu/items/{$this->menuItem->id}/recipe", [
        'lines' => [
            ['ingredient_id' => $this->beef->ingredient_id, 'quantity' => 0.2],
            ['ingredient_id' => $this->rice->ingredient_id, 'quantity' => 0.1],
        ],
    ])->assertOk();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('flags an expense category for item cost allocation', function (): void {
    $categoryId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Rent',
        'code' => 'rent',
        'allocates_to_item_cost' => true,
    ])->assertCreated()
        ->assertJsonPath('data.allocates_to_item_cost', true)
        ->json('data.id');

    $this->patchJson("/api/v1/expense-categories/{$categoryId}", [
        'name' => 'Rent',
        'allocates_to_item_cost' => false,
    ])->assertOk()
        ->assertJsonPath('data.allocates_to_item_cost', false);

    $this->postJson('/api/v1/expense-categories', ['name' => 'Repairs'])
        ->assertCreated()
        ->assertJsonPath('data.allocates_to_item_cost', false);
});

it('splits approved operating expenses evenly across paid items', function (): void {
    $rentId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Rent',
        'allocates_to_item_cost' => true,
    ])->assertCreated()->json('data.id');
    $waterId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Water',
        'allocates_to_item_cost' => true,
    ])->assertCreated()->json('data.id');
    $repairsId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Repairs',
    ])->assertCreated()->json('data.id');
    $gasId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Gas',
        'allocates_to_item_cost' => true,
    ])->assertCreated()->json('data.id');

    recordItemCostExpense($rentId, $this->branch->id, 30, '2026-10-01');
    recordItemCostExpense($waterId, $this->branch->id, 6, '2026-10-10');
    recordItemCostExpense($repairsId, $this->branch->id, 50, '2026-10-05');
    recordItemCostExpense($gasId, $this->branch->id, 20, '2026-10-08', 'voided');
    recordItemCostExpense($rentId, $this->branch->id, 40, '2026-10-12', 'pending');
    recordItemCostExpense($rentId, $this->branch->id, 80, '2026-09-01');
    recordItemCostExpense($rentId, $this->otherBranch->id, 100, '2026-10-03');

    $paid = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 360,
        'created_at' => now(),
    ]);
    $parent = OrderItem::create([
        'order_id' => $paid->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 120,
        'quantity' => 3,
        'subtotal' => 360,
        'status' => 'served',
    ]);
    OrderItem::create([
        'order_id' => $paid->id,
        'parent_id' => $parent->id,
        'line_type' => 'package_component',
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => 'Component',
        'unit_price' => 0,
        'quantity' => 9,
        'subtotal' => 0,
        'status' => 'served',
    ]);

    $cancelled = Order::factory()->cancelled()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $cancelled->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 120,
        'quantity' => 5,
        'subtotal' => 600,
        'status' => 'cancelled',
    ]);

    $previousMonth = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'created_at' => '2026-09-20 12:00:00',
    ]);
    OrderItem::create([
        'order_id' => $previousMonth->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 120,
        'quantity' => 7,
        'subtotal' => 840,
        'status' => 'served',
    ]);

    $otherBranchOrder = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->otherBranch->id,
        'created_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $otherBranchOrder->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 120,
        'quantity' => 1,
        'subtotal' => 120,
        'status' => 'served',
    ]);

    $response = $this->getJson('/api/v1/expenses/item-cost?branch_id='.$this->branch->id)
        ->assertOk()
        ->assertJsonPath('data.method', 'quantity_sold')
        ->assertJsonPath('data.start_date', '2026-10-01')
        ->assertJsonPath('data.end_date', '2026-10-31')
        ->assertJsonPath('data.categories.0.category_name', 'Rent')
        ->assertJsonPath('data.categories.1.category_name', 'Water');

    expect((float) $response->json('data.overhead_pool'))->toBe(36.0);
    expect((float) $response->json('data.units_sold'))->toBe(3.0);
    expect((float) $response->json('data.overhead_per_unit'))->toBe(12.0);
    expect((float) $response->json('data.categories.0.total'))->toBe(30.0);
    expect((float) $response->json('data.categories.1.total'))->toBe(6.0);

    $allBranches = $this->getJson('/api/v1/expenses/item-cost')->assertOk();
    expect((float) $allBranches->json('data.overhead_pool'))->toBe(136.0);
    expect((float) $allBranches->json('data.units_sold'))->toBe(4.0);
    expect((float) $allBranches->json('data.overhead_per_unit'))->toBe(34.0);
});

it('adds overhead to recipe cost and keeps food cost unchanged', function (): void {
    $rentId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Rent',
        'allocates_to_item_cost' => true,
    ])->json('data.id');
    recordItemCostExpense($rentId, $this->branch->id, 36, '2026-10-01');

    $order = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 120,
        'quantity' => 3,
        'subtotal' => 360,
        'status' => 'served',
    ]);

    $response = $this->getJson("/api/v1/menu/items/{$this->menuItem->id}/cost?branch_id={$this->branch->id}")
        ->assertOk();

    expect((float) $response->json('data.total_cost'))->toBe(11.0);
    expect((float) $response->json('data.food_cost'))->toBe(11.0);
    expect((float) $response->json('data.overhead_cost'))->toBe(12.0);
    expect((float) $response->json('data.full_cost'))->toBe(23.0);
    expect((float) $response->json('data.profit_margin'))->toEqual(90.83);
    expect((float) $response->json('data.true_margin'))->toEqual(80.83);
    expect((float) $this->menuItem->fresh()->cost_price)->toBe(11.0);
});

it('leaves overhead at zero when nothing was sold', function (): void {
    $rentId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Rent',
        'allocates_to_item_cost' => true,
    ])->json('data.id');
    recordItemCostExpense($rentId, $this->branch->id, 36, '2026-10-01');

    $allocation = $this->getJson('/api/v1/expenses/item-cost?branch_id='.$this->branch->id)
        ->assertOk();

    expect((float) $allocation->json('data.overhead_pool'))->toBe(36.0);
    expect((float) $allocation->json('data.units_sold'))->toBe(0.0);
    expect((float) $allocation->json('data.overhead_per_unit'))->toBe(0.0);

    $cost = $this->getJson("/api/v1/menu/items/{$this->menuItem->id}/cost?branch_id={$this->branch->id}")
        ->assertOk();

    expect((float) $cost->json('data.full_cost'))->toBe(11.0);
    expect((float) $cost->json('data.overhead.overhead_pool'))->toBe(36.0);
});

it('includes overhead on the top items report', function (): void {
    $rentId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Rent',
        'allocates_to_item_cost' => true,
    ])->json('data.id');
    recordItemCostExpense($rentId, $this->branch->id, 36, '2026-10-01');

    $order = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'created_at' => now(),
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 120,
        'quantity' => 3,
        'subtotal' => 360,
        'status' => 'served',
    ]);

    $response = $this->getJson('/api/v1/reports/top-items?branch_id='.$this->branch->id.'&start_date=2026-10-01&end_date=2026-10-31')
        ->assertOk();

    expect((float) $response->json('data.item_cost.overhead_per_unit'))->toBe(12.0);
    expect((float) $response->json('data.items.0.food_cost'))->toBe(11.0);
    expect((float) $response->json('data.items.0.overhead_cost'))->toBe(12.0);
    expect((float) $response->json('data.items.0.full_cost'))->toBe(23.0);
    expect((float) $response->json('data.items.0.food_cost_total'))->toBe(33.0);
    expect((float) $response->json('data.items.0.overhead_cost_total'))->toBe(36.0);
    expect((float) $response->json('data.items.0.full_cost_total'))->toBe(69.0);
    expect((float) $response->json('data.items.0.true_margin'))->toEqual(80.83);
    expect($response->json('data.items.0.profit_margin'))->not->toBeNull();
});

function recordItemCostExpense(
    int $categoryId,
    int $branchId,
    float $amount,
    string $date,
    string $status = 'approved',
): void {
    $expenseId = test()->postJson('/api/v1/expenses', [
        'branch_id' => $branchId,
        'expense_category_id' => $categoryId,
        'amount' => $amount,
        'payment_method' => 'bank_transfer',
        'description' => 'Operating bill',
        'expense_date' => $date,
    ])->assertCreated()->json('data.id');

    if ($status === 'pending') {
        return;
    }

    test()->postJson("/api/v1/expenses/{$expenseId}/approve")->assertOk();

    if ($status === 'voided') {
        test()->postJson("/api/v1/expenses/{$expenseId}/void", [
            'reason' => 'Duplicate bill',
        ])->assertOk();
    }
}
