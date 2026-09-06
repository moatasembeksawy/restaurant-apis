<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);
    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);
    $this->mainCategory = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name_ar' => 'وجبات',
    ]);
    $this->drinksCategory = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name_ar' => 'مشروبات',
    ]);
    $this->burger = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->mainCategory->id,
        'name_ar' => 'برجر',
    ]);
    $this->cola = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->drinksCategory->id,
        'name_ar' => 'كولا',
    ]);
    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);

    foreach ([$this->burger, $this->cola] as $item) {
        OrderItem::create([
            'order_id' => $this->order->id,
            'menu_item_id' => $item->id,
            'item_name_ar' => $item->name_ar,
            'unit_price' => 50,
            'quantity' => 1,
            'subtotal' => 50,
            'status' => 'pending',
        ]);
    }

    app()->instance('tenant', $this->tenant);
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');
});

function createKitchenPrinter(Branch $branch, string $name, string $key, bool $default = false): int
{
    return test()->postJson('/api/v1/print/printers', [
        'branch_id' => $branch->id,
        'name' => $name,
        'bridge_key' => $key,
        'type' => 'kitchen',
        'is_default_kitchen' => $default,
    ])->assertCreated()->json('data.id');
}

it('splits one order into jobs using direct category printer routes', function (): void {
    $hotPrinter = createKitchenPrinter($this->branch, 'Hot Kitchen', 'hot-kitchen', true);
    $barPrinter = createKitchenPrinter($this->branch, 'Bar', 'bar');

    $this->putJson("/api/v1/print/routes/categories/{$this->mainCategory->id}", [
        'branch_id' => $this->branch->id,
        'targets' => [['printer_id' => $hotPrinter]],
    ])->assertOk();
    $this->putJson("/api/v1/print/routes/categories/{$this->drinksCategory->id}", [
        'branch_id' => $this->branch->id,
        'targets' => [['printer_id' => $barPrinter]],
    ])->assertOk();

    $response = $this->getJson("/api/v1/orders/{$this->order->id}/print/kitchen/jobs")
        ->assertOk()
        ->assertJsonCount(2, 'data.jobs');

    $jobs = collect($response->json('data.jobs'))->keyBy('bridge_key');

    expect($jobs['hot-kitchen']['items'])->toHaveCount(1)
        ->and($jobs['hot-kitchen']['items'][0]['name'])->toBe('برجر')
        ->and($jobs['bar']['items'])->toHaveCount(1)
        ->and($jobs['bar']['items'][0]['name'])->toBe('كولا');
});

it('uses item routes before category routes', function (): void {
    $defaultPrinter = createKitchenPrinter($this->branch, 'Kitchen 1', 'kitchen-1', true);
    $overridePrinter = createKitchenPrinter($this->branch, 'Kitchen 2', 'kitchen-2');

    $this->putJson("/api/v1/print/routes/categories/{$this->mainCategory->id}", [
        'branch_id' => $this->branch->id,
        'targets' => [['printer_id' => $defaultPrinter]],
    ])->assertOk();
    $this->putJson("/api/v1/print/routes/items/{$this->burger->id}", [
        'branch_id' => $this->branch->id,
        'targets' => [['printer_id' => $overridePrinter]],
    ])->assertOk();

    $jobs = collect(
        $this->getJson("/api/v1/orders/{$this->order->id}/print/kitchen/jobs")
            ->assertOk()
            ->json('data.jobs'),
    )->keyBy('bridge_key');

    expect($jobs['kitchen-2']['items'][0]['name'])->toBe('برجر')
        ->and($jobs['kitchen-1']['items'][0]['name'])->toBe('كولا');
});

it('expands an optional station route to its assigned printer', function (): void {
    $fallback = createKitchenPrinter($this->branch, 'Fallback', 'fallback', true);
    $grillPrinter = createKitchenPrinter($this->branch, 'Grill Printer', 'grill');

    $stationId = $this->postJson('/api/v1/print/stations', [
        'branch_id' => $this->branch->id,
        'name' => 'Grill',
        'printer_ids' => [$grillPrinter],
    ])->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/print/settings/{$this->branch->id}", [
        'printing_mode' => 'stations',
    ])->assertOk();
    $this->putJson("/api/v1/print/routes/categories/{$this->mainCategory->id}", [
        'branch_id' => $this->branch->id,
        'targets' => [['kitchen_station_id' => $stationId]],
    ])->assertOk();

    $jobs = collect(
        $this->getJson("/api/v1/orders/{$this->order->id}/print/kitchen/jobs")
            ->assertOk()
            ->json('data.jobs'),
    )->keyBy('bridge_key');

    expect($jobs['grill']['station_names'])->toBe(['Grill'])
        ->and($jobs['grill']['items'][0]['name'])->toBe('برجر')
        ->and($jobs['fallback']['items'][0]['name'])->toBe('كولا');
});

it('uses the branch default printer for items without routes', function (): void {
    createKitchenPrinter($this->branch, 'Default Kitchen', 'default-kitchen', true);

    $this->getJson("/api/v1/orders/{$this->order->id}/print/kitchen/jobs")
        ->assertOk()
        ->assertJsonCount(1, 'data.jobs')
        ->assertJsonPath('data.jobs.0.bridge_key', 'default-kitchen')
        ->assertJsonCount(2, 'data.jobs.0.items');
});

it('returns a clear error when the branch has no kitchen printer', function (): void {
    $this->getJson("/api/v1/orders/{$this->order->id}/print/kitchen/jobs")
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PRINT_ROUTING_FAILED');
});
