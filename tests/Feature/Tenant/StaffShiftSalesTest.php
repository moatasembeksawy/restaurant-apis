<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Staff\Models\StaffShift;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create([
        'plan' => 'pro',
        'status' => 'active',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 100.00,
        'is_available' => true,
    ]);

    $table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'occupied',
    ]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'floor_table_id' => $table->id,
        'status' => 'ready',
        'subtotal' => 100.00,
        'total' => 100.00,
    ]);

    OrderItem::create([
        'order_id' => $this->order->id,
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => $menuItem->name_ar,
        'unit_price' => 100.00,
        'quantity' => 1,
        'subtotal' => 100.00,
        'status' => 'ready',
    ]);

    app()->instance('tenant', $this->tenant);
});

function payOrderAs(User $user, Order $order): TestResponse
{
    Sanctum::actingAs($user, ['*'], 'sanctum');

    return test()->postJson("/api/v1/orders/{$order->id}/pay", [
        'method' => 'cash',
        'amount' => (float) $order->total,
        'cash_tendered' => (float) $order->total,
    ]);
}

it('requires cashiers to clock in before taking payments on pro plans', function (): void {
    payOrderAs($this->cashier, $this->order)
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'NO_ACTIVE_SHIFT');
});

it('links payments to the active cashier shift and reports shift sales', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 200])
        ->assertCreated()
        ->assertJsonPath('data.opening_float', '200.00');

    payOrderAs($this->cashier, $this->order)->assertOk();

    $shift = StaffShift::query()->where('user_id', $this->cashier->id)->first();

    expect(Payment::query()->where('staff_shift_id', $shift->id)->count())->toBe(1);

    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $this->getJson('/api/v1/staff/shifts/current')
        ->assertOk()
        ->assertJsonPath('data.sales.orders_count', 1)
        ->assertJsonPath('data.sales.gross_sales', 100)
        ->assertJsonPath('data.sales.cash_collected', 100)
        ->assertJsonPath('data.sales.expected_cash_in_drawer', 300);
});

it('records cash variance when a shift is closed', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 100])->assertCreated();
    payOrderAs($this->cashier, $this->order)->assertOk();

    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $this->postJson('/api/v1/staff/shifts/clock-out', ['closing_cash_count' => 195])
        ->assertOk()
        ->assertJsonPath('data.expected_cash', '200.00')
        ->assertJsonPath('data.cash_variance', '-5.00')
        ->assertJsonPath('data.sales.net_sales', 100);
});

it('allows managers to settle payments without an active shift', function (): void {
    payOrderAs($this->manager, $this->order)
        ->assertOk()
        ->assertJsonPath('data.payment.method', 'cash');

    expect(Payment::query()->value('staff_shift_id'))->toBeNull();
});

it('attributes refunds to the manager active shift when present', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');
    $this->postJson('/api/v1/staff/shifts/clock-in')->assertCreated();
    payOrderAs($this->cashier, $this->order)->assertOk();

    Sanctum::actingAs($this->manager, ['*'], 'sanctum');
    $this->postJson('/api/v1/staff/shifts/clock-in')->assertCreated();

    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $this->postJson("/api/v1/orders/{$this->order->id}/refund", ['reason' => 'Wrong order'])
        ->assertOk();

    $managerShiftId = StaffShift::query()
        ->where('user_id', $this->manager->id)
        ->value('id');

    expect($managerShiftId)->not->toBeNull();

    $this->getJson("/api/v1/staff/shifts/{$managerShiftId}")
        ->assertOk()
        ->assertJsonPath('data.sales.refunds_count', 1)
        ->assertJsonPath('data.sales.refunds_total', 100);
});

it('does not require shifts on starter plans without staff_shifts feature', function (): void {
    $starter = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
    app()->instance('tenant', $starter);

    $cashier = User::factory()->create([
        'tenant_id' => $starter->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $starter->id])->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $order = Order::factory()->create([
        'tenant_id' => $starter->id,
        'branch_id' => $cashier->branch_id,
        'status' => 'ready',
        'subtotal' => 50,
        'total' => 50,
    ]);

    payOrderAs($cashier, $order)->assertOk();
});
