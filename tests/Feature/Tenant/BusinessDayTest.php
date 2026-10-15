<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\BusinessDays\Models\BusinessDay;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Staff\Models\StaffShift;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->warehouse = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Main warehouse',
        'is_default' => true,
    ]);
    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Downtown',
        'is_default' => false,
    ]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);
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
        'price' => 80,
        'is_available' => true,
    ]);

    $this->stock = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
        'unit_cost' => 4,
    ]);

    InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->warehouse->id,
        'ingredient_id' => $this->stock->ingredient_id,
        'current_stock' => 50,
        'unit_cost' => 4,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->managerToken = $this->manager->createToken('test')->plainTextToken;
    $this->cashierToken = $this->cashier->createToken('test')->plainTextToken;
});

it('opens a business day and snapshots remaining ingredients', function (): void {
    $response = $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', [
            'branch_id' => $this->branch->id,
            'notes' => 'Morning open',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.is_open', true)
        ->assertJsonPath('data.branch_id', $this->branch->id)
        ->assertJsonPath('data.next_order_number', 1);

    $dayId = $response->json('data.id');

    $this->withToken($this->managerToken)
        ->getJson("/api/v1/business-days/current?branch_id={$this->branch->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $dayId);

    $remaining = $this->withToken($this->managerToken)
        ->getJson("/api/v1/business-days/{$dayId}/remaining-stock")
        ->assertOk();

    expect($remaining->json('data.items.0.ingredient_id'))->toBe($this->stock->ingredient_id);
    expect((float) $remaining->json('data.items.0.quantity'))->toBe(10.0);
});

it('attaches shifts and restarts daily order numbers after close', function (): void {
    $dayId = $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', ['branch_id' => $this->branch->id])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/staff/shifts/clock-in', ['branch_id' => $this->branch->id])
        ->assertCreated()
        ->assertJsonPath('data.business_day_id', $dayId);

    $first = $this->withToken($this->cashierToken)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'fulfillment_type' => 'takeaway',
            'items' => [['menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
        ])
        ->assertCreated();

    $second = $this->withToken($this->cashierToken)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'fulfillment_type' => 'takeaway',
            'items' => [['menu_item_id' => $this->menuItem->id, 'quantity' => 2]],
        ])
        ->assertCreated();

    expect($first->json('data.business_day_id'))->toBe($dayId);
    expect($first->json('data.business_day_order_number'))->toBe(1);
    expect($first->json('data.daily_number'))->toBe(1);
    expect($second->json('data.business_day_order_number'))->toBe(2);

    $paidTotal = (float) $first->json('data.total');

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/orders/'.$first->json('data.id').'/pay', [
            'method' => 'cash',
            'amount' => $paidTotal,
        ])
        ->assertOk();

    $this->withToken($this->managerToken)
        ->patchJson('/api/v1/orders/'.$second->json('data.id').'/status', [
            'status' => 'cancelled',
        ])
        ->assertOk();

    $this->withToken($this->managerToken)
        ->getJson("/api/v1/orders?business_day_id={$dayId}")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->withToken($this->managerToken)
        ->getJson("/api/v1/staff/shifts?business_day_id={$dayId}")
        ->assertOk();

    expect(StaffShift::query()->where('business_day_id', $dayId)->count())->toBe(1);

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/close")
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'BUSINESS_DAY_CLOSE_FAILED');

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/staff/shifts/clock-out', ['closing_cash_count' => $paidTotal])
        ->assertOk();

    $closed = $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/close", ['notes' => 'End of day'])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.is_open', false);

    expect($closed->json('data.closing_summary.orders.paid'))->toBe(1);
    expect((float) $closed->json('data.closing_summary.payments.by_method.cash.total'))->toBe($paidTotal);
    expect($closed->json('data.closing_summary.items.0.quantity'))->toBe(1);

    $nextDay = $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', ['branch_id' => $this->branch->id])
        ->assertCreated()
        ->assertJsonPath('data.next_order_number', 1);

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/staff/shifts/clock-in', ['branch_id' => $this->branch->id])
        ->assertCreated();

    $this->withToken($this->cashierToken)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'fulfillment_type' => 'takeaway',
            'items' => [['menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.business_day_id', $nextDay->json('data.id'))
        ->assertJsonPath('data.business_day_order_number', 1)
        ->assertJsonPath('data.daily_number', 1);
});

it('records waste against remaining ingredients', function (): void {
    $dayId = $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', ['branch_id' => $this->branch->id])
        ->json('data.id');

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/waste", [
            'ingredient_id' => $this->stock->ingredient_id,
            'quantity' => 2.5,
            'reason' => 'Spoiled tomatoes',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'waste')
        ->assertJsonPath('data.business_day_id', $dayId);

    expect((float) $this->stock->fresh()->current_stock)->toBe(7.5);
    expect(StockMovement::query()->where('type', 'waste')->where('business_day_id', $dayId)->count())->toBe(1);

    $remaining = $this->withToken($this->managerToken)
        ->getJson("/api/v1/business-days/{$dayId}/remaining-stock")
        ->json('data.items.0.quantity');

    expect((float) $remaining)->toBe(7.5);

    $report = $this->withToken($this->managerToken)
        ->getJson("/api/v1/business-days/{$dayId}/report")
        ->assertOk();

    expect((float) $report->json('data.inventory.waste.0.quantity'))->toBe(2.5);
    expect((float) $report->json('data.inventory.ledger.0.waste'))->toBe(2.5);
    expect((float) $report->json('data.inventory.ledger.0.remaining'))->toBe(7.5);
});

it('returns remaining stock to the main warehouse', function (): void {
    $dayId = $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', ['branch_id' => $this->branch->id])
        ->json('data.id');

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/waste", [
            'ingredient_id' => $this->stock->ingredient_id,
            'quantity' => 1,
            'reason' => 'Prep trim',
        ])
        ->assertCreated();

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/return-stock", [
            'to_branch_id' => $this->warehouse->id,
            'return_remaining' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'in_transit')
        ->assertJsonPath('data.to_branch_id', $this->warehouse->id);

    expect((float) $this->stock->fresh()->current_stock)->toBe(0.0);

    $report = $this->withToken($this->managerToken)
        ->getJson("/api/v1/business-days/{$dayId}/report")
        ->assertOk();

    expect((float) $report->json('data.inventory.returned.0.quantity'))->toBe(9.0);
    expect((float) $report->json('data.inventory.ledger.0.returned'))->toBe(9.0);
    expect((float) $report->json('data.inventory.ledger.0.remaining'))->toBe(0.0);
});

it('rejects a second open day and waste after close', function (): void {
    $dayId = $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', ['branch_id' => $this->branch->id])
        ->json('data.id');

    $this->withToken($this->managerToken)
        ->postJson('/api/v1/business-days/open', ['branch_id' => $this->branch->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'BUSINESS_DAY_OPEN_FAILED');

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/close")
        ->assertOk();

    $this->withToken($this->managerToken)
        ->postJson("/api/v1/business-days/{$dayId}/waste", [
            'ingredient_id' => $this->stock->ingredient_id,
            'quantity' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'STOCK_ERROR');

    expect(BusinessDay::query()->where('status', 'closed')->count())->toBe(1);
});
