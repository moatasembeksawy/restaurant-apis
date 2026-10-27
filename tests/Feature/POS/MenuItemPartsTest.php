<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'is_default' => true]);
    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);
    $this->category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->sandwich = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'ساندوتش فراخ',
        'price' => 80,
        'is_available' => true,
    ]);
    $this->fries = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'بطاطس',
        'price' => 25,
        'is_available' => true,
    ]);
    $this->rice = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'رز',
        'price' => 20,
        'is_available' => true,
    ]);
    $this->salad = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'سلطة',
        'price' => 15,
        'is_available' => true,
    ]);
    $this->cola = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'كولا',
        'price' => 15,
        'is_available' => true,
    ]);
    $this->juice = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'name_ar' => 'عصير',
        'price' => 20,
        'is_available' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    startCashierShift($this->manager);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('creates a meal with a swap and a choice part', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/menu/items', [
            'category_id' => $this->category->id,
            'name_ar' => 'وجبة فراخ',
            'price' => 180,
            'parts' => [
                [
                    'type' => 'fixed',
                    'menu_item_id' => $this->sandwich->id,
                ],
                [
                    'type' => 'swap',
                    'name_ar' => 'الجانب',
                    'menu_item_id' => $this->fries->id,
                    'options' => [
                        ['menu_item_id' => $this->rice->id, 'extra_price' => 5],
                        ['menu_item_id' => $this->salad->id, 'extra_price' => 0],
                    ],
                ],
                [
                    'type' => 'choice',
                    'name_ar' => 'المشروب',
                    'min_select' => 1,
                    'max_select' => 1,
                    'options' => [
                        ['menu_item_id' => $this->cola->id],
                        ['menu_item_id' => $this->juice->id, 'extra_price' => 5],
                    ],
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name_ar', 'وجبة فراخ')
        ->assertJsonPath('data.parts.1.type', 'swap')
        ->assertJsonPath('data.parts.2.type', 'choice');

    expect($response->json('data.parts'))->toHaveCount(3);
});

it('updates a swap when the client echoes the parent item as menu_item_id', function (): void {
    $meal = createChickenMeal($this);
    $swap = collect($meal['parts'])->firstWhere('type', 'swap');

    $this->withToken($this->token)
        ->patchJson('/api/v1/menu/items/'.$meal['id'], [
            'parts' => [[
                'type' => 'swap',
                'name_ar' => 'السلطات',
                'menu_item_id' => $meal['id'],
                'options' => [
                    ['menu_item_id' => $this->salad->id, 'extra_price' => 0, 'is_available' => true],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.parts.0.type', 'swap')
        ->assertJsonPath('data.parts.0.default_menu_item_id', null)
        ->assertJsonPath('data.parts.0.options.0.menu_item_id', $this->salad->id)
        ->assertJsonMissingPath('data.parts.0.menu_item_id');

    expect($swap['default_menu_item_id'])->toBe($this->fries->id);
    expect($meal['parts'][1]['menu_item_id'])->toBe($this->fries->id);
    expect($meal['parts'][1]['menu_item_id'])->not->toBe($meal['id']);
});

it('keeps the stored swap default when the same part is saved with the parent id', function (): void {
    $meal = createChickenMeal($this);
    $swap = collect($meal['parts'])->firstWhere('type', 'swap');

    $this->withToken($this->token)
        ->patchJson('/api/v1/menu/items/'.$meal['id'], [
            'parts' => [[
                'id' => $swap['id'],
                'type' => 'swap',
                'name_ar' => $swap['name_ar'],
                'menu_item_id' => $meal['id'],
                'options' => [
                    ['menu_item_id' => $this->rice->id, 'extra_price' => 5, 'is_available' => true],
                    ['menu_item_id' => $this->salad->id, 'extra_price' => 0, 'is_available' => true],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.parts.0.default_menu_item_id', $this->fries->id)
        ->assertJsonPath('data.parts.0.menu_item_id', $this->fries->id);
});

it('rejects a choice part without options', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/menu/items', [
            'category_id' => $this->category->id,
            'name_ar' => 'وجبة ناقصة',
            'price' => 100,
            'parts' => [
                [
                    'type' => 'choice',
                    'name_ar' => 'المشروب',
                    'options' => [],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'MENU_ITEM_VALIDATION_FAILED');
});

it('prices a meal as one line and explodes chosen dishes for the kitchen', function (): void {
    $meal = createChickenMeal($this);
    $swap = collect($meal['parts'])->firstWhere('type', 'swap');
    $choice = collect($meal['parts'])->firstWhere('type', 'choice');

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
                'selections' => [
                    ['part_id' => $swap['id'], 'menu_item_id' => $this->rice->id],
                    ['part_id' => $choice['id'], 'menu_item_id' => $this->juice->id],
                ],
            ]],
        ])
        ->assertCreated();

    $items = collect($response->json('data.items'));
    $parent = $items->firstWhere('parent_id', null);
    $children = $items->whereNotNull('parent_id');

    expect((float) $parent['unit_price'])->toBe(190.0);
    expect((float) $response->json('data.subtotal'))->toBe(190.0);
    expect($children)->toHaveCount(3);
    expect($children->pluck('menu_item_id')->all())->toContain($this->sandwich->id, $this->rice->id, $this->juice->id);
    expect(OrderItem::query()->where('line_type', OrderItem::LINE_ITEM_COMPONENT)->count())->toBe(3);
});

it('keeps the default swap and auto-fills a single remaining drink', function (): void {
    $meal = createChickenMeal($this, juiceAvailable: false);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
            ]],
        ])
        ->assertCreated();

    $children = collect($response->json('data.items'))->whereNotNull('parent_id');

    expect((float) $response->json('data.subtotal'))->toBe(180.0);
    expect($children->pluck('menu_item_id')->all())->toContain($this->fries->id, $this->cola->id);
});

it('hides a sold-out swap option from the order', function (): void {
    $this->rice->update(['is_available' => false]);
    $meal = createChickenMeal($this);
    $swap = collect($meal['parts'])->firstWhere('type', 'swap');
    $choice = collect($meal['parts'])->firstWhere('type', 'choice');

    $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
                'selections' => [
                    ['part_id' => $swap['id'], 'menu_item_id' => $this->rice->id],
                    ['part_id' => $choice['id'], 'menu_item_id' => $this->cola->id],
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'ORDER_VALIDATION_FAILED');
});

it('accepts arabic weight units and stores g or kg', function (): void {
    $fish = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name_ar' => 'سمك',
        'unit' => 'kg',
        'is_active' => true,
    ]);

    $created = $this->withToken($this->token)
        ->postJson('/api/v1/menu/items', [
            'category_id' => $this->category->id,
            'name_ar' => 'سمك مشوي',
            'price' => 250,
            'parts' => [[
                'type' => 'weight',
                'name_ar' => 'السمك',
                'ingredient_id' => $fish->id,
                'included_quantity' => 500,
                'unit' => 'جم',
                'price_per_unit' => 0.30,
            ]],
        ])
        ->assertCreated();

    expect($created->json('data.parts.0.unit'))->toBe('g');

    $this->withToken($this->token)
        ->patchJson('/api/v1/menu/items/'.$created->json('data.id'), [
            'parts' => [[
                'type' => 'weight',
                'name_ar' => 'السمك',
                'ingredient_id' => $fish->id,
                'included_quantity' => 0.5,
                'unit' => 'كجم',
                'price_per_unit' => 300,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.parts.0.unit', 'kg');
});

it('creates a fish meal priced by the real weight', function (): void {
    [$meal, $denise, $bouri] = createFishMeal($this);
    $fishPart = collect($meal['parts'])->firstWhere('type', 'weight');

    $default = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
            ]],
        ])
        ->assertCreated();

    expect((float) $default->json('data.subtotal'))->toBe(250.0);

    $heavier = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
                'selections' => [
                    ['part_id' => $fishPart['id'], 'ingredient_id' => $denise->id, 'quantity' => 610],
                ],
            ]],
        ])
        ->assertCreated();

    expect((float) $heavier->json('data.subtotal'))->toBe(283.0);
    expect($heavier->json('data.items.0.selections.0.label'))->toContain('دينيس');

    $bouriOrder = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
                'selections' => [
                    ['part_id' => $fishPart['id'], 'ingredient_id' => $bouri->id, 'quantity' => 610],
                ],
            ]],
        ])
        ->assertCreated();

    expect((float) $bouriOrder->json('data.subtotal'))->toBe(344.0);
    expect($bouriOrder->json('data.items'))->toHaveCount(1);
});

it('deducts the weighed fish and the fixed side recipe when the meal is paid', function (): void {
    [$meal, $denise, $bouri] = createFishMeal($this);
    $fishPart = collect($meal['parts'])->firstWhere('type', 'weight');
    $riceStock = InventoryStock::query()->where('ingredient_id', $this->riceIngredient->id)->firstOrFail();
    $deniseStock = InventoryStock::query()->where('ingredient_id', $denise->id)->firstOrFail();
    $bouriStock = InventoryStock::query()->where('ingredient_id', $bouri->id)->firstOrFail();

    $order = $this->withToken($this->token)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->branch->id,
            'channel' => 'dine_in',
            'items' => [[
                'menu_item_id' => $meal['id'],
                'quantity' => 1,
                'selections' => [
                    ['part_id' => $fishPart['id'], 'ingredient_id' => $bouri->id, 'quantity' => 610],
                ],
            ]],
        ])
        ->assertCreated();

    $this->withToken($this->token)
        ->postJson('/api/v1/orders/'.$order->json('data.id').'/pay', [
            'method' => 'cash',
            'amount' => $order->json('data.total'),
        ])
        ->assertOk();

    expect(round((float) $riceStock->fresh()->current_stock, 2))->toBe(9.85);
    expect(round((float) $deniseStock->fresh()->current_stock, 2))->toBe(5.0);
    expect(round((float) $bouriStock->fresh()->current_stock, 2))->toBe(4.39);
});

/**
 * @return array<string, mixed>
 */
function createChickenMeal(object $ctx, bool $juiceAvailable = true): array
{
    $response = test()->withToken($ctx->token)
        ->postJson('/api/v1/menu/items', [
            'category_id' => $ctx->category->id,
            'name_ar' => 'وجبة فراخ',
            'price' => 180,
            'parts' => [
                [
                    'type' => 'fixed',
                    'menu_item_id' => $ctx->sandwich->id,
                ],
                [
                    'type' => 'swap',
                    'name_ar' => 'الجانب',
                    'menu_item_id' => $ctx->fries->id,
                    'options' => [
                        ['menu_item_id' => $ctx->rice->id, 'extra_price' => 5],
                        ['menu_item_id' => $ctx->salad->id, 'extra_price' => 0],
                    ],
                ],
                [
                    'type' => 'choice',
                    'name_ar' => 'المشروب',
                    'min_select' => 1,
                    'max_select' => 1,
                    'options' => [
                        ['menu_item_id' => $ctx->cola->id],
                        ['menu_item_id' => $ctx->juice->id, 'extra_price' => 5, 'is_available' => $juiceAvailable],
                    ],
                ],
            ],
        ])
        ->assertCreated();

    return $response->json('data');
}

/**
 * @return array{0: array<string, mixed>, 1: Ingredient, 2: Ingredient}
 */
function createFishMeal(object $ctx): array
{
    $denise = Ingredient::factory()->create([
        'tenant_id' => $ctx->tenant->id,
        'name_ar' => 'دينيس',
        'unit' => 'kg',
        'is_active' => true,
    ]);
    $bouri = Ingredient::factory()->create([
        'tenant_id' => $ctx->tenant->id,
        'name_ar' => 'بوري',
        'unit' => 'kg',
        'is_active' => true,
    ]);
    $rice = Ingredient::factory()->create([
        'tenant_id' => $ctx->tenant->id,
        'name_ar' => 'رز',
        'unit' => 'kg',
        'is_active' => true,
    ]);

    InventoryStock::factory()->create([
        'tenant_id' => $ctx->tenant->id,
        'branch_id' => $ctx->branch->id,
        'ingredient_id' => $denise->id,
        'current_stock' => 5,
    ]);
    InventoryStock::factory()->create([
        'tenant_id' => $ctx->tenant->id,
        'branch_id' => $ctx->branch->id,
        'ingredient_id' => $bouri->id,
        'current_stock' => 5,
    ]);
    InventoryStock::factory()->create([
        'tenant_id' => $ctx->tenant->id,
        'branch_id' => $ctx->branch->id,
        'ingredient_id' => $rice->id,
        'current_stock' => 10,
    ]);

    $response = test()->withToken($ctx->token)
        ->postJson('/api/v1/menu/items', [
            'category_id' => $ctx->category->id,
            'name_ar' => 'وجبة سمك مشوي',
            'price' => 250,
            'parts' => [
                [
                    'type' => 'weight',
                    'name_ar' => 'السمك',
                    'ingredient_id' => $denise->id,
                    'included_quantity' => 500,
                    'unit' => 'g',
                    'price_per_unit' => 0.30,
                    'options' => [
                        ['ingredient_id' => $bouri->id, 'price_per_unit' => 0.40],
                    ],
                ],
            ],
        ])
        ->assertCreated();

    $meal = MenuItem::query()->findOrFail($response->json('data.id'));

    Recipe::create([
        'tenant_id' => $ctx->tenant->id,
        'menu_item_id' => $meal->id,
        'ingredient_id' => $rice->id,
        'quantity' => 0.15,
    ]);
    Recipe::create([
        'tenant_id' => $ctx->tenant->id,
        'menu_item_id' => $meal->id,
        'ingredient_id' => $denise->id,
        'quantity' => 0.5,
    ]);

    $ctx->riceIngredient = $rice;

    return [$response->json('data'), $denise, $bouri];
}
