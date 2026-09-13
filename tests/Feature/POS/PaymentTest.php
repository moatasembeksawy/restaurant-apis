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

it('recalculates vat and service after a payment discount, not on the original product total', function (): void {
    Queue::fake();

    $this->order->update([
        'fulfillment_type' => 'dine_in',
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
    ]);
    $this->order->recalculateTotals();

    expect((float) $this->order->fresh()->total)->toBe(127.68);

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 102.14,
            'cash_tendered' => 110.00,
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'discount_reason' => 'خصم موظف',
        ])
        ->assertOk();

    $paid = $this->order->fresh();

    expect((float) $paid->discount)->toBe(20.0);
    expect((float) $paid->service_charge)->toBe(9.6);
    expect((float) $paid->tax)->toBe(12.54);
    expect((float) $paid->total)->toBe(102.14);
});

it('applies a percentage discount to the product subtotal before vat and service', function (): void {
    Queue::fake();

    $this->order->update([
        'fulfillment_type' => 'dine_in',
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
    ]);
    $this->order->recalculateTotals();

    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 114.91,
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ])
        ->assertOk();

    $paid = $this->order->fresh();

    expect((float) $paid->discount)->toBe(10.0);
    expect((float) $paid->service_charge)->toBe(10.8);
    expect((float) $paid->tax)->toBe(14.11);
    expect((float) $paid->total)->toBe(114.91);
});
