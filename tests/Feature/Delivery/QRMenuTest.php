<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Events\OrderPlaced;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([OrderPlaced::class]);

    $this->tenant = Tenant::factory()->create([
        'plan' => 'growth',
        'status' => 'active',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $this->category = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_visible' => true,
    ]);

    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 75.00,
        'is_available' => true,
    ]);

    $this->table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);
});

it('returns menu for a valid qr token', function (): void {
    $this->getJson("/api/v1/qr/{$this->table->qr_token}/menu")
        ->assertOk()
        ->assertJsonPath('data.source', 'table')
        ->assertJsonPath('data.restaurant.name', $this->tenant->name)
        ->assertJsonPath('data.table.name', $this->table->name)
        ->assertJsonStructure([
            'data' => ['source', 'menu_url', 'restaurant', 'branch', 'table', 'categories'],
        ]);
});

it('returns branch menu from shareable branch qr token', function (): void {
    $this->branch->refresh();

    $this->getJson("/api/v1/qr/{$this->branch->qr_menu_token}/menu")
        ->assertOk()
        ->assertJsonPath('data.source', 'branch')
        ->assertJsonPath('data.table', null)
        ->assertJsonPath('data.branch.id', $this->branch->id)
        ->assertJsonStructure(['data' => ['menu_url', 'categories']]);
});

it('places a pickup order via branch menu without a table', function (): void {
    $this->branch->refresh();

    $response = $this->postJson("/api/v1/qr/{$this->branch->qr_menu_token}/orders", [
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
        'customer_phone' => '01098765432',
        'customer_name' => 'Sara',
        'notes' => 'Pickup in 20 minutes',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.source', 'branch')
        ->assertJsonPath('data.fulfillment_type', 'takeaway');

    $order = Order::query()->where('channel', 'qr')->first();
    expect($order->fulfillment_type)->toBe('takeaway');
    expect($order->floor_table_id)->toBeNull();
    expect($order->branch_id)->toBe($this->branch->id);
    expect($this->table->fresh()->status)->toBe('free');
});

it('filters branch menu to shared and branch-specific categories', function (): void {
    $otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'name_ar' => 'فرع آخر',
        'is_visible' => true,
    ]);

    MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => MenuCategory::query()->where('name_ar', 'فرع آخر')->value('id'),
        'is_available' => true,
    ]);

    $this->branch->refresh();

    $response = $this->getJson("/api/v1/qr/{$this->branch->qr_menu_token}/menu")->assertOk();

    $categoryNames = collect($response->json('data.categories'))->pluck('name_ar');
    expect($categoryNames)->toContain($this->category->name_ar);
    expect($categoryNames)->not->toContain('فرع آخر');
});

it('exposes qr_menu_url on branch listing for staff', function (): void {
    $owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonStructure(['data' => [['qr_menu_token', 'qr_menu_url']]]);
});

it('places an order via qr menu', function (): void {
    $response = $this->postJson("/api/v1/qr/{$this->table->qr_token}/orders", [
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 2],
        ],
        'customer_phone' => '01012345678',
        'customer_name' => 'أحمد',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.fulfillment_type', 'dine_in');

    expect(Order::query()->where('channel', 'qr')->first()->fulfillment_type)->toBe('dine_in');
    expect($this->table->fresh()->status)->toBe('occupied');
});

it('places a delivery order via branch qr menu', function (): void {
    $this->branch->refresh();

    $this->postJson("/api/v1/qr/{$this->branch->qr_menu_token}/orders", [
        'fulfillment_type' => 'delivery',
        'delivery_address' => '12 Abbas El Akkad, Nasr City',
        'customer_phone' => '01055554444',
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.fulfillment_type', 'delivery');

    $order = Order::query()->where('channel', 'qr')->first();
    expect($order->fulfillment_type)->toBe('delivery');
    expect($order->delivery_address)->toBe('12 Abbas El Akkad, Nasr City');
    expect($order->delivery_status)->toBe('pending');
});

it('requires delivery address for qr delivery orders', function (): void {
    $this->branch->refresh();

    $this->postJson("/api/v1/qr/{$this->branch->qr_menu_token}/orders", [
        'fulfillment_type' => 'delivery',
        'customer_phone' => '01055554444',
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
    ])
        ->assertUnprocessable();
});

it('returns 404 for invalid qr token', function (): void {
    $this->getJson('/api/v1/qr/invalid-token/menu')
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'INVALID_QR_TOKEN');
});

it('blocks qr menu when plan does not include feature', function (): void {
    $this->tenant->update(['plan' => 'starter']);

    $this->getJson("/api/v1/qr/{$this->table->qr_token}/menu")
        ->assertStatus(402)
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
