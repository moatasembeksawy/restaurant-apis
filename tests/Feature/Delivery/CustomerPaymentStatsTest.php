<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'visit_count' => 0,
        'total_spent' => 0,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 100.00,
        'is_available' => true,
    ]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
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
    $this->token = $this->cashier->createToken('test', ['billing:*'])->plainTextToken;
});

it('updates customer stats when order is paid', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
        ])
        ->assertOk();

    $customer = $this->customer->fresh();
    expect($customer->visit_count)->toBe(1);
    expect((float) $customer->total_spent)->toBe(100.0);
    expect($customer->last_order_at)->not->toBeNull();

    Queue::assertPushed(SubmitETAInvoiceJob::class);
});

it('does not update customer stats on unpaid qr order', function (): void {
    $table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->postJson("/api/v1/qr/{$table->qr_token}/orders", [
        'items' => [['menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
        'customer_phone' => $this->customer->phone,
    ])->assertCreated();

    expect($this->customer->fresh()->visit_count)->toBe(0);
    expect((float) $this->customer->fresh()->total_spent)->toBe(0.0);
});
