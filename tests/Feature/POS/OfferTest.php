<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'is_default' => true]);
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
    $this->category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 100,
        'is_available' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('creates an offer and auto-applies it on place', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/offers', [
            'name_ar' => 'خصم 10%',
            'type' => 'percentage',
            'value' => 10,
            'target_type' => 'order',
            'channels' => ['dine_in', 'qr', 'whatsapp', 'own_delivery'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'percentage');

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    expect((float) $response->json('data.discount'))->toBe(10.0);
    expect((float) $response->json('data.total'))->toBe(90.0);
});

it('applies a coupon code and records it on the order', function (): void {
    Offer::factory()->coupon('RAMADAN')->fixed(20)->create([
        'tenant_id' => $this->tenant->id,
        'target_type' => 'order',
        'channels' => ['dine_in', 'qr', 'whatsapp', 'own_delivery'],
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'coupon_code' => 'ramadan',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    expect($response->json('data.coupon_code'))->toBe('RAMADAN');
    expect((float) $response->json('data.discount'))->toBe(20.0);
});

it('rejects an unknown coupon', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'coupon_code' => 'NOPE',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
});

it('stacks a cashier discount on top of an offer', function (): void {
    Queue::fake();

    Offer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'percentage',
        'value' => 10,
        'target_type' => 'order',
        'channels' => ['dine_in', 'qr', 'whatsapp', 'own_delivery'],
    ]);

    $orderResponse = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $orderId = $orderResponse->json('data.id');
    startCashierShift($this->cashier);

    $this->withToken($this->cashier->createToken('test')->plainTextToken)
        ->postJson("/api/v1/orders/{$orderId}/pay", [
            'method' => 'cash',
            'amount' => 80.00,
            'discount_type' => 'fixed',
            'discount_value' => 10,
        ])
        ->assertOk();

    $paid = Order::query()->findOrFail($orderId);
    expect((float) $paid->discount)->toBe(20.0);
    expect((float) $paid->total)->toBe(80.0);
});

it('blocks offers on growth plans', function (): void {
    $growth = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    app()->instance('tenant', $growth);
    $user = User::factory()->create([
        'tenant_id' => $growth->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/v1/offers', [
            'name_ar' => 'عرض',
            'type' => 'fixed',
            'value' => 5,
            'target_type' => 'order',
        ])
        ->assertPaymentRequired();
});

it('previews the winning auto offer', function (): void {
    Offer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name_ar' => 'خصم 10%',
        'type' => 'percentage',
        'value' => 10,
        'target_type' => 'order',
        'channels' => ['dine_in'],
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/offers/preview', [
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.discount', 10);
});
