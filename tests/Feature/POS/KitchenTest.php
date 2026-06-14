<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Event;
use App\Modules\POS\Orders\Events\OrderItemReady;
use App\Modules\POS\Orders\Events\OrderReady;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'pro',
        'status' => 'active',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $this->cook = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cook',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
    ]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    $this->item = OrderItem::create([
        'order_id' => $this->order->id,
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => 'كباب',
        'unit_price' => 50.00,
        'quantity' => 2,
        'subtotal' => 100.00,
        'status' => 'pending',
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->cook->createToken('test', ['kitchen:*'])->plainTextToken;
});

it('marks a kitchen item as ready and updates order status', function (): void {
    Event::fake([OrderItemReady::class, OrderReady::class]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/kitchen/items/{$this->item->id}/done")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');

    expect($this->order->fresh()->status)->toBe('ready');
    expect($this->item->fresh()->cooked_at)->not->toBeNull();

    Event::assertDispatched(OrderItemReady::class);
    Event::assertDispatched(OrderReady::class);
});

it('returns kitchen queue with pending items', function (): void {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/kitchen/queue?branch_id='.$this->branch->id)
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.items'))->toHaveCount(1);
});

it('sets order to cooking when only some items are ready', function (): void {
    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
    ]);

    OrderItem::create([
        'order_id' => $this->order->id,
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => 'سلطة',
        'unit_price' => 25.00,
        'quantity' => 1,
        'subtotal' => 25.00,
        'status' => 'pending',
    ]);

    Event::fake([OrderItemReady::class, OrderReady::class]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/kitchen/items/{$this->item->id}/done")
        ->assertOk();

    expect($this->order->fresh()->status)->toBe('cooking');

    Event::assertDispatched(OrderItemReady::class);
    Event::assertNotDispatched(OrderReady::class);
});
