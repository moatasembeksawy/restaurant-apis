<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Loyalty\Models\LoyaltyTransaction;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'loyalty_points' => 200,
    ]);

    $this->customerForAccrual = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'loyalty_points' => 0,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('shows loyalty profile for a customer', function (): void {
    LoyaltyTransaction::create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'type' => 'earn',
        'points' => 10,
        'balance_after' => 10,
        'monetary_value' => 100,
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/loyalty/customers/{$this->customer->id}")
        ->assertOk();

    expect($response->json('data.points'))->toBe(200);
    expect($response->json('data.recent_transactions'))->toHaveCount(1);
});

it('redeems loyalty points for a discount value', function (): void {
    $response = $this->withToken($this->token)
        ->postJson("/api/v1/loyalty/customers/{$this->customer->id}/redeem", [
            'points' => 100,
        ])
        ->assertOk();

    expect($response->json('data.points_redeemed'))->toBe(100);
    expect((float) $response->json('data.discount_egp'))->toBe(25.0);
    expect($response->json('data.balance'))->toBe(100);
    expect($this->customer->fresh()->loyalty_points)->toBe(100);
});

it('rejects redemption below minimum points', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/loyalty/customers/{$this->customer->id}/redeem", [
            'points' => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'LOYALTY_REDEEM_FAILED');
});

it('rejects redemption when balance is insufficient', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/loyalty/customers/{$this->customer->id}/redeem", [
            'points' => 500,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'LOYALTY_REDEEM_FAILED');
});

it('accrues loyalty points when an order is paid', function (): void {
    Queue::fake();

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 100.00,
    ]);

    $cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    startCashierShift($cashier);

    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customerForAccrual->id,
        'status' => 'ready',
        'subtotal' => 100.00,
        'total' => 100.00,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => $menuItem->name_ar,
        'unit_price' => 100.00,
        'quantity' => 1,
        'subtotal' => 100.00,
        'status' => 'ready',
    ]);

    $token = $cashier->createToken('test', ['billing:*'])->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/v1/orders/{$order->id}/pay", [
            'method' => 'cash',
            'amount' => 100.00,
        ])
        ->assertOk();

    expect($this->customerForAccrual->fresh()->loyalty_points)->toBe(10);
    expect(LoyaltyTransaction::query()->where('customer_id', $this->customerForAccrual->id)->count())->toBe(1);

    Queue::assertPushed(SubmitETAInvoiceJob::class);
});

it('blocks loyalty endpoints on non-enterprise plans', function (): void {
    $starter = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
    app()->instance('tenant', $starter);

    $user = User::factory()->create([
        'tenant_id' => $starter->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $customer = Customer::factory()->create(['tenant_id' => $starter->id]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/loyalty/customers/{$customer->id}")
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
