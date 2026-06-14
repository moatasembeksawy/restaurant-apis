<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'loyalty_points' => 200,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 100.00,
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
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => $menuItem->name_ar,
        'unit_price' => 100.00,
        'quantity' => 1,
        'subtotal' => 100.00,
        'status' => 'ready',
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->cashier->createToken('test', ['billing:*'])->plainTextToken;
});

it('applies loyalty points as discount during payment', function (): void {
    Queue::fake();

    $response = $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 75.00,
            'cash_tendered' => 100.00,
            'loyalty_points' => 100,
        ])
        ->assertOk();

    expect((float) $response->json('data.loyalty_redemption.discount_egp'))->toBe(25.0);
    expect($response->json('data.loyalty_redemption.points_redeemed'))->toBe(100);
    expect((float) $this->order->fresh()->total)->toBe(75.0);
    expect($this->customer->fresh()->loyalty_points)->toBe(107);

    Queue::assertPushed(SubmitETAInvoiceJob::class);
});

it('rejects loyalty redemption exceeding customer balance', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
            'loyalty_points' => 500,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PAYMENT_FAILED');
});
