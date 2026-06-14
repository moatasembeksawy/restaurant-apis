<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Recipes\Models\Recipe;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 120.00,
    ]);

    $this->beef = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'unit_cost' => 50.00,
    ]);

    $this->rice = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'unit_cost' => 10.00,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('syncs recipe and calculates food cost', function (): void {
    $this->withToken($this->token)
        ->putJson("/api/v1/menu/items/{$this->menuItem->id}/recipe", [
            'lines' => [
                ['ingredient_id' => $this->beef->id, 'quantity' => 0.2],
                ['ingredient_id' => $this->rice->id, 'quantity' => 0.1],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.total_cost', 11)
        ->assertJsonPath('data.menu_price', 120);

    expect(Recipe::query()->where('menu_item_id', $this->menuItem->id)->count())->toBe(2);
});

it('calculates profit margin from recipe cost', function (): void {
    Recipe::create([
        'tenant_id' => $this->tenant->id,
        'menu_item_id' => $this->menuItem->id,
        'ingredient_id' => $this->beef->id,
        'quantity' => 0.5,
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/menu/items/{$this->menuItem->id}/cost")
        ->assertOk();

    expect((float) $response->json('data.total_cost'))->toBe(25.0);
    expect((float) $response->json('data.profit_margin'))->toBeGreaterThan(0);
});
