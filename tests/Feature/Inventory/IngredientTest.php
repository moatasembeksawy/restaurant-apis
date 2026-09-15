<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
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

it('creates and lists ingredients', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => $this->branch->id,
            'name_ar' => 'لحم بقري',
            'name_en' => 'Beef',
            'unit' => 'kg',
            'current_stock' => 20,
            'reorder_level' => 5,
            'unit_cost' => 250,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name_ar', 'لحم بقري')
        ->assertJsonPath('data.sku', 'BEEF-KG');

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/inventory/ingredients')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('reuses the master ingredient when the same item is opened at another branch', function (): void {
    $other = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $first = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => $this->branch->id,
            'name_ar' => 'طماطم',
            'name_en' => 'Tomato',
            'unit' => 'kg',
        ])
        ->assertCreated();

    $second = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/stock', [
            'ingredient_id' => $first->json('data.id'),
            'branch_id' => $other->id,
            'current_stock' => 3,
        ])
        ->assertCreated();

    expect($second->json('data.ingredient_id'))->toBe($first->json('data.id'));
    expect($second->json('data.name_ar'))->toBe('طماطم');
    expect(Ingredient::query()->count())->toBe(1);

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/stock', [
            'ingredient_id' => $first->json('data.id'),
            'branch_id' => $this->branch->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'INGREDIENT_ERROR');
});

it('lists ingredients with branch stock and a total', function (): void {
    $other = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $first = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => $this->branch->id,
            'name_ar' => 'طماطم',
            'name_en' => 'Tomato',
            'unit' => 'kg',
            'current_stock' => 20,
        ])
        ->assertCreated();

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/stock', [
            'ingredient_id' => $first->json('data.id'),
            'branch_id' => $other->id,
            'current_stock' => 3,
        ])
        ->assertCreated();

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/inventory/ingredients')
        ->assertOk()
        ->assertJsonPath('data.0.name_ar', 'طماطم')
        ->assertJsonPath('data.0.total_stock', 23)
        ->assertJsonPath('data.0.branch_count', 2);

    $stocks = collect($response->json('data.0.stocks'))->pluck('current_stock');
    expect($stocks->contains('20.000'))->toBeTrue();
    expect($stocks->contains('3.000'))->toBeTrue();
});

it('returns low stock at a branch', function (): void {
    InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 2,
        'reorder_level' => 10,
    ]);

    InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 50,
        'reorder_level' => 10,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/inventory/low-stock')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('records waste movement and reduces stock', function (): void {
    $stock = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
        'reorder_level' => 2,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/movements', [
            'ingredient_id' => $stock->ingredient_id,
            'branch_id' => $this->branch->id,
            'type' => 'waste',
            'quantity' => 3,
            'notes' => 'Spoilage',
        ])
        ->assertCreated();

    expect((float) $stock->fresh()->current_stock)->toBe(7.0);
});

it('blocks inventory routes on starter plan', function (): void {
    $this->tenant->update(['plan' => 'starter']);

    $this->withToken($this->token)
        ->getJson('/api/v1/inventory/ingredients')
        ->assertStatus(402)
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
