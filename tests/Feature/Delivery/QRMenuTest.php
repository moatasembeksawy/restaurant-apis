<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Event;
use App\Modules\POS\Orders\Events\OrderPlaced;

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
        ->assertJsonPath('data.restaurant.name', $this->tenant->name)
        ->assertJsonPath('data.table.name', $this->table->name)
        ->assertJsonStructure([
            'data' => ['restaurant', 'branch', 'table', 'categories'],
        ]);
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
        ->assertJsonPath('data.status', 'active');

    expect(Order::query()->where('channel', 'qr')->count())->toBe(1);
    expect($this->table->fresh()->status)->toBe('occupied');
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
