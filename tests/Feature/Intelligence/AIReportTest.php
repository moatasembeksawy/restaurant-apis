<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Carbon\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-14 12:00:00');

    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('generates weekly AI summary with insights', function (): void {
    $weekStart = now()->startOfWeek();

    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'dine_in',
        'total' => 500.00,
        'created_at' => $weekStart->copy()->addDay(),
    ]);

    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'talabat',
        'total' => 300.00,
        'created_at' => $weekStart->copy()->addDays(2),
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/reports/ai-summary')
        ->assertOk();

    expect($response->json('data.summary.paid_orders'))->toBe(2);
    expect((float) $response->json('data.summary.total_revenue'))->toBe(800.0);
    expect($response->json('data.insights'))->not->toBeEmpty();
    expect($response->json('data.period.start'))->toBe($weekStart->toDateString());
});

it('includes top items in weekly summary', function (): void {
    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 50.00,
    ]);

    $order = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 100.00,
        'created_at' => now(),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => $menuItem->name_ar,
        'unit_price' => 50.00,
        'quantity' => 2,
        'subtotal' => 100.00,
        'status' => 'ready',
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/reports/ai-summary')
        ->assertOk();

    expect($response->json('data.top_items.0.menu_item_id'))->toBe($menuItem->id);
    expect($response->json('data.top_items.0.total_qty'))->toBe(2);
});

it('blocks AI reports on non-enterprise plans', function (): void {
    $pro = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    app()->instance('tenant', $pro);

    $user = User::factory()->create([
        'tenant_id' => $pro->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/v1/reports/ai-summary')
        ->assertPaymentRequired();
});
