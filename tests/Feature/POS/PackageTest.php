<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

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
