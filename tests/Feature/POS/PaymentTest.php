<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
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

    $this->category = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 100.00,
        'is_available' => true,
    ]);

    $this->table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'occupied',
    ]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'floor_table_id' => $this->table->id,
        'status' => 'ready',
        'subtotal' => 100.00,
        'total' => 100.00,
    ]);

    OrderItem::create([
        'order_id' => $this->order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 100.00,
        'quantity' => 1,
        'subtotal' => 100.00,
        'status' => 'ready',
    ]);

    app()->instance('tenant', $this->tenant);
    startCashierShift($this->cashier);
    $this->token = $this->cashier->createToken('test', ['billing:*', 'orders:*'])->plainTextToken;
});

it('settles payment and dispatches ETA invoice job', function (): void {
    Queue::fake();

    $response = $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
            'cash_tendered' => 150.00,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.payment.method', 'cash')
        ->assertJsonPath('data.invoice.eta_status', 'pending')
        ->assertJsonPath('data.change_due', 50);

    expect($this->order->fresh()->status)->toBe('paid');
    expect($this->table->fresh()->status)->toBe('free');

    Queue::assertPushed(SubmitETAInvoiceJob::class);
});

it('rejects payment for already paid orders', function (): void {
    $this->order->update(['status' => 'paid']);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_ALREADY_PAID');
});

it('logs payment in audit trail', function (): void {
    Queue::fake();

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'card',
            'amount' => 100.00,
        ])
        ->assertOk();

    $log = Activity::query()
        ->where('description', 'payment.settled')
        ->where('properties->tenant_id', $this->tenant->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->properties['method'])->toBe('card');
});

it('returns receipt print bytes after payment', function (): void {
    Queue::fake();

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
        ])
        ->assertOk();

    $this->withToken($this->token)
        ->getJson("/api/v1/orders/{$this->order->id}/print/receipt")
        ->assertOk()
        ->assertJsonPath('data.format', 'escpos')
        ->assertJsonStructure(['data' => ['bytes']]);
});

it('returns kitchen ticket print bytes for active orders', function (): void {
    $this->order->update(['status' => 'active']);

    $this->withToken($this->token)
        ->getJson("/api/v1/orders/{$this->order->id}/print/kitchen")
        ->assertOk()
        ->assertJsonPath('data.format', 'escpos')
        ->assertJsonStructure(['data' => ['bytes']]);
});
