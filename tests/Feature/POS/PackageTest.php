<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\POS\Packages\Services\PackageService;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'is_default' => true]);
    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);
    $this->category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->pizza = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'بيتزا',
        'price' => 80,
        'is_available' => true,
    ]);
    $this->cola = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'كولا',
        'price' => 15,
        'is_available' => true,
    ]);
    $this->fries = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'بطاطس',
        'price' => 25,
        'is_available' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('creates a package with a fixed slot even when options is empty', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $this->category->id,
            'name_ar' => 'وجبة ثابتة',
            'price' => 100,
            'slots' => [
                [
                    'type' => 'fixed',
                    'menu_item_id' => $this->fries->id,
                    'quantity' => 1,
                    'options' => [],
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.slots.0.type', 'fixed')
        ->assertJsonPath('data.slots.0.menu_item_id', $this->fries->id);

    expect(MenuPackage::query()->count())->toBe(1);
});

it('rejects a choice slot without options', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $this->category->id,
            'name_ar' => 'وجبة اختيار',
            'price' => 100,
            'slots' => [
                [
                    'type' => 'choice',
                    'name_ar' => 'اختار البيتزا',
                    'options' => [],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
});

it('creates a package with fixed and choice slots', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $this->category->id,
            'name_ar' => 'وجبة عائلية',
            'price' => 150,
            'slots' => [
                [
                    'type' => 'choice',
                    'name_ar' => 'اختار البيتزا',
                    'min_select' => 1,
                    'max_select' => 1,
                    'options' => [
                        ['menu_item_id' => $this->pizza->id, 'extra_price' => 10],
                    ],
                ],
                [
                    'type' => 'fixed',
                    'menu_item_id' => $this->fries->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name_ar', 'وجبة عائلية');

    expect(MenuPackage::query()->count())->toBe(1);
    expect($response->json('data.slots'))->toHaveCount(2);
});

it('explodes a package into priced parent and kitchen components', function (): void {
    $package = createFamilyPackage($this);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                [
                    'package_id' => $package->id,
                    'quantity' => 1,
                    'selections' => [
                        ['slot_id' => $package->slots->firstWhere('type', 'choice')->id, 'menu_item_ids' => [$this->pizza->id]],
                    ],
                ],
            ],
        ])
        ->assertCreated();

    $items = $response->json('data.items');
    $parents = collect($items)->whereNull('parent_id');
    $components = collect($items)->whereNotNull('parent_id');

    expect($parents)->toHaveCount(1);
    expect((float) $parents->first()['unit_price'])->toBe(160.0);
    expect($components)->toHaveCount(2);
    expect((float) $response->json('data.subtotal'))->toBe(160.0);
    expect(OrderItem::query()->where('line_type', OrderItem::LINE_PACKAGE)->count())->toBe(1);
});

it('places a choice package using the chosen menu_item_id without a selections array', function (): void {
    $package = createFamilyPackage($this);

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                [
                    'package_id' => $package->id,
                    'menu_item_id' => $this->pizza->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.package_id', $package->id);
});

it('places a choice package when selections use slot id and a single menu_item_id', function (): void {
    $package = createFamilyPackage($this);
    $choiceSlot = $package->slots->firstWhere('type', 'choice');

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                [
                    'package_id' => $package->id,
                    'quantity' => 1,
                    'selections' => [
                        ['id' => $choiceSlot->id, 'menu_item_id' => $this->pizza->id],
                    ],
                ],
            ],
        ])
        ->assertCreated();
});

it('auto-fills a choice slot that has exactly one available option', function (): void {
    $package = createFamilyPackage($this);

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                [
                    'package_id' => $package->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.package_id', $package->id);
});

it('places a multi-option choice package when the selected item is sent without a slot_id', function (): void {
    $package = createChoicePackage($this, [$this->pizza->id, $this->cola->id]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                [
                    'package_id' => $package->id,
                    'quantity' => 1,
                    'menu_item_ids' => [$this->cola->id],
                ],
            ],
        ])
        ->assertCreated();

    $components = collect($response->json('data.items'))->whereNotNull('parent_id');

    expect($components->pluck('menu_item_id')->all())->toContain($this->cola->id);
});

it('rejects a multi-option choice package when no selection is provided', function (): void {
    $package = createChoicePackage($this, [$this->pizza->id, $this->cola->id]);

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [
                [
                    'package_id' => $package->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
});

it('blocks packages on starter plans', function (): void {
    $starter = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
    app()->instance('tenant', $starter);
    $user = User::factory()->create([
        'tenant_id' => $starter->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/v1/menu/packages')
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});

it('allows enterprise to create a package even if PackageService was resolved before tenant middleware', function (): void {
    app()->forgetInstance(PackageService::class);
    app()->forgetInstance(PlanLimitService::class);
    app()->forgetInstance('tenant');

    // Laravel can construct the controller (and this singleton) before tenant middleware binds the tenant.
    app(PackageService::class);

    $enterprise = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $category = MenuCategory::factory()->create(['tenant_id' => $enterprise->id]);
    $item = MenuItem::factory()->create([
        'tenant_id' => $enterprise->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);
    $user = User::factory()->create([
        'tenant_id' => $enterprise->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $enterprise);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $category->id,
            'name_ar' => 'وجبة مؤسسية',
            'price' => 80,
            'slots' => [
                [
                    'type' => 'fixed',
                    'menu_item_id' => $item->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name_ar', 'وجبة مؤسسية');
});

it('allows creating a package when starter has the menu_packages feature flag', function (): void {
    $starter = Tenant::factory()->create([
        'plan' => 'starter',
        'status' => 'active',
        'feature_flags' => ['menu_packages'],
    ]);
    $category = MenuCategory::factory()->create(['tenant_id' => $starter->id]);
    $item = MenuItem::factory()->create([
        'tenant_id' => $starter->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);
    $user = User::factory()->create([
        'tenant_id' => $starter->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $starter);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $category->id,
            'name_ar' => 'وجبة تجريبية',
            'price' => 80,
            'slots' => [
                [
                    'type' => 'fixed',
                    'menu_item_id' => $item->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name_ar', 'وجبة تجريبية');
});

function createFamilyPackage(object $ctx): MenuPackage
{
    $response = test()->withToken($ctx->token)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $ctx->category->id,
            'name_ar' => 'وجبة عائلية',
            'price' => 150,
            'slots' => [
                [
                    'type' => 'choice',
                    'name_ar' => 'اختار البيتزا',
                    'min_select' => 1,
                    'max_select' => 1,
                    'options' => [
                        ['menu_item_id' => $ctx->pizza->id, 'extra_price' => 10],
                    ],
                ],
                [
                    'type' => 'fixed',
                    'menu_item_id' => $ctx->fries->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertCreated();

    return MenuPackage::query()->with('slots')->findOrFail($response->json('data.id'));
}

/**
 * @param  list<int>  $menuItemIds
 */
function createChoicePackage(object $ctx, array $menuItemIds): MenuPackage
{
    $response = test()->withToken($ctx->token)
        ->postJson('/api/v1/menu/packages', [
            'category_id' => $ctx->category->id,
            'name_ar' => 'وجبة اختيار',
            'price' => 120,
            'slots' => [
                [
                    'type' => 'choice',
                    'name_ar' => 'اختار الصنف',
                    'min_select' => 1,
                    'max_select' => 1,
                    'options' => array_map(
                        fn (int $id): array => ['menu_item_id' => $id],
                        $menuItemIds,
                    ),
                ],
            ],
        ])
        ->assertCreated();

    return MenuPackage::query()->with('slots')->findOrFail($response->json('data.id'));
}
