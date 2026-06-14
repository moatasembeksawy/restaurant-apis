<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Loyalty\Models\LoyaltyTransaction;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\POS\Billing\Models\PaymentRefund;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

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
        'price' => 100.00,
    ]);

    $this->ingredient = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
    ]);

    Recipe::create([
        'tenant_id' => $this->tenant->id,
        'menu_item_id' => $this->menuItem->id,
        'ingredient_id' => $this->ingredient->id,
        'quantity' => 1,
    ]);

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'loyalty_points' => 200,
        'visit_count' => 5,
        'total_spent' => 500,
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
    startCashierShift($this->cashier);
});

function payOrder(object $test, User $user, int $orderId, array $payload = []): void
{
    Sanctum::actingAs($user, ['*'], 'sanctum');

    $test->postJson("/api/v1/orders/{$orderId}/pay", array_merge([
        'method' => 'cash',
        'amount' => 100.00,
    ], $payload))
        ->assertOk();
}

function refundOrder(object $test, User $user, int $orderId, array $payload = []): TestResponse
{
    Sanctum::actingAs($user, ['*'], 'sanctum');

    return $test->postJson("/api/v1/orders/{$orderId}/refund", $payload);
}

it('refunds a paid order and restores inventory stock', function (): void {
    payOrder($this, $this->cashier, $this->order->id);

    expect((float) $this->ingredient->fresh()->current_stock)->toBe(9.0);

    $response = refundOrder($this, $this->manager, $this->order->id, [
        'reason' => 'Customer complaint',
    ])->assertOk()->assertJsonPath('data.order.status', 'refunded');

    expect($response->json('data.refund.amount'))->toBe('100.00');
    expect($this->order->fresh()->status)->toBe('refunded');
    expect(PaymentRefund::query()->count())->toBe(1);
    expect((float) $this->ingredient->fresh()->current_stock)->toBe(10.0);
    expect(StockMovement::query()->where('type', 'refund')->count())->toBe(1);
});

it('reverses loyalty earn and redeem on refund', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'web');

    $this->postJson("/api/v1/orders/{$this->order->id}/pay", [
        'method' => 'cash',
        'amount' => 75.00,
        'loyalty_points' => 100,
    ])->assertOk();

    expect($this->customer->fresh()->loyalty_points)->toBe(107);

    refundOrder($this, $this->manager, $this->order->id)->assertOk();

    expect($this->customer->fresh()->loyalty_points)->toBe(200);
    expect(LoyaltyTransaction::query()->where('type', 'adjustment')->count())->toBe(2);
    expect($this->customer->fresh()->visit_count)->toBe(5);
    expect((float) $this->customer->fresh()->total_spent)->toBe(500.0);
});

it('voids eta invoice on refund', function (): void {
    payOrder($this, $this->cashier, $this->order->id);

    $payment = $this->order->fresh()->payment;

    refundOrder($this, $this->manager, $this->order->id)->assertOk();

    expect($payment->fresh()->invoice->eta_status)->toBe('voided');
});

it('rejects duplicate refunds', function (): void {
    payOrder($this, $this->cashier, $this->order->id);

    refundOrder($this, $this->manager, $this->order->id)->assertOk();

    refundOrder($this, $this->manager, $this->order->id)
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ALREADY_REFUNDED');
});

it('forbids cashiers from issuing refunds', function (): void {
    payOrder($this, $this->cashier, $this->order->id);

    refundOrder($this, $this->cashier, $this->order->id)
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('logs refund in audit trail', function (): void {
    payOrder($this, $this->cashier, $this->order->id);

    refundOrder($this, $this->manager, $this->order->id, ['reason' => 'Wrong order'])->assertOk();

    $log = Activity::query()
        ->where('description', 'payment.refunded')
        ->where('properties->tenant_id', $this->tenant->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->properties['reason'])->toBe('Wrong order');
});
