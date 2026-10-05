<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentRefund;
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

it('reports how many times each item was paid and the money for each item', function (): void {
    $categoryId = MenuItem::query()->value('category_id');
    $firstItem = MenuItem::query()->first();

    $secondItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $categoryId,
        'name_ar' => 'سلطة',
        'price' => 40.00,
        'is_available' => true,
    ]);

    $secondOrder = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'ready',
        'subtotal' => 240.00,
        'total' => 240.00,
    ]);

    OrderItem::create([
        'order_id' => $secondOrder->id,
        'menu_item_id' => $firstItem->id,
        'item_name_ar' => $firstItem->name_ar,
        'unit_price' => 100.00,
        'quantity' => 2,
        'subtotal' => 200.00,
        'status' => 'ready',
    ]);

    OrderItem::create([
        'order_id' => $secondOrder->id,
        'menu_item_id' => $secondItem->id,
        'item_name_ar' => $secondItem->name_ar,
        'unit_price' => 40.00,
        'quantity' => 1,
        'subtotal' => 40.00,
        'status' => 'ready',
    ]);

    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');
    $this->postJson('/api/v1/staff/shifts/clock-in')->assertCreated();

    payOrderAs($this->cashier, $this->order)->assertOk();
    payOrderAs($this->cashier, $secondOrder)->assertOk();

    $shiftId = StaffShift::query()->where('user_id', $this->cashier->id)->value('id');

    $this->getJson('/api/v1/staff/shifts/current')
        ->assertOk()
        ->assertJsonMissingPath('data.sales.items');

    $report = $this->getJson("/api/v1/staff/shifts/{$shiftId}/items")->assertOk();

    $items = collect($report->json('data.items'));

    $first = $items->firstWhere('menu_item_id', $firstItem->id);
    $second = $items->firstWhere('menu_item_id', $secondItem->id);

    expect($first)->toMatchArray([
        'menu_item_id' => $firstItem->id,
        'name_ar' => $firstItem->name_ar,
        'quantity' => 3,
        'total' => 300,
    ])->and($second)->toMatchArray([
        'menu_item_id' => $secondItem->id,
        'name_ar' => 'سلطة',
        'quantity' => 1,
        'total' => 40,
    ])->and($report->json('data.totals'))->toMatchArray([
        'quantity' => 4,
        'total' => 340,
    ]);

    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $this->postJson("/api/v1/orders/{$secondOrder->id}/refund", ['reason' => 'Wrong order'])
        ->assertOk();

    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $afterRefund = $this->getJson("/api/v1/staff/shifts/{$shiftId}/items")->assertOk();

    expect(collect($afterRefund->json('data.items')))->toHaveCount(1)
        ->and($afterRefund->json('data.items.0'))->toMatchArray([
            'menu_item_id' => $firstItem->id,
            'quantity' => 1,
            'total' => 100,
        ])
        ->and($afterRefund->json('data.totals'))->toMatchArray([
            'quantity' => 1,
            'total' => 100,
        ]);
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

it('requires managers and owners to clock in before taking payments on pro plans', function (string $role): void {
    $user = $role === 'manager'
        ? $this->manager
        : User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

    payOrderAs($user, $this->order)
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'NO_ACTIVE_SHIFT');
})->with(['manager', 'owner']);

it('links a manager payment to their open shift', function (): void {
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');
    $this->postJson('/api/v1/staff/shifts/clock-in')->assertCreated();

    payOrderAs($this->manager, $this->order)
        ->assertOk()
        ->assertJsonPath('data.payment.method', 'cash');

    $shiftId = StaffShift::query()->where('user_id', $this->manager->id)->value('id');

    expect(Payment::query()->value('staff_shift_id'))->toBe($shiftId);
});

it('does not subtract cash refunds twice from expected cash in drawer', function (): void {
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 500])->assertCreated();
    payOrderAs($this->manager, $this->order)->assertOk();

    $this->postJson("/api/v1/orders/{$this->order->id}/refund", ['reason' => 'Wrong order'])
        ->assertOk();

    $this->getJson('/api/v1/staff/shifts/current')
        ->assertOk()
        ->assertJsonPath('data.sales.gross_sales', 100)
        ->assertJsonPath('data.sales.refunds_total', 100)
        ->assertJsonPath('data.sales.net_sales', 0)
        ->assertJsonPath('data.sales.cash_collected', 100)
        ->assertJsonPath('data.sales.cash_refunded', 100)
        ->assertJsonPath('data.sales.expected_cash_in_drawer', 500);
});

it('attributes refunds to the shift that recorded the payment', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');
    $this->postJson('/api/v1/staff/shifts/clock-in')->assertCreated();
    payOrderAs($this->cashier, $this->order)->assertOk();

    $cashierShiftId = StaffShift::query()
        ->where('user_id', $this->cashier->id)
        ->value('id');

    Sanctum::actingAs($this->manager, ['*'], 'sanctum');
    $this->postJson('/api/v1/staff/shifts/clock-in')->assertCreated();

    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $this->postJson("/api/v1/orders/{$this->order->id}/refund", ['reason' => 'Wrong order'])
        ->assertOk();

    $managerShiftId = StaffShift::query()
        ->where('user_id', $this->manager->id)
        ->value('id');

    expect($cashierShiftId)->not->toBeNull()
        ->and($managerShiftId)->not->toBeNull()
        ->and(PaymentRefund::query()->value('staff_shift_id'))->toBe($cashierShiftId);

    $this->getJson("/api/v1/staff/shifts/{$cashierShiftId}")
        ->assertOk()
        ->assertJsonPath('data.sales.refunds_count', 1)
        ->assertJsonPath('data.sales.refunds_total', 100)
        ->assertJsonPath('data.sales.net_sales', 0);

    $this->getJson("/api/v1/staff/shifts/{$managerShiftId}")
        ->assertOk()
        ->assertJsonPath('data.sales.refunds_count', 0)
        ->assertJsonPath('data.sales.refunds_total', 0);
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
