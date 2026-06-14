<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
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
        ->assertJsonPath('data.name_ar', 'لحم بقري');

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/inventory/ingredients')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('returns low stock ingredients', function (): void {
    Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 2,
        'reorder_level' => 10,
    ]);

    Ingredient::factory()->create([
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
    $ingredient = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
        'reorder_level' => 2,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'waste',
            'quantity' => 3,
            'notes' => 'Spoilage',
        ])
        ->assertCreated();

    expect((float) $ingredient->fresh()->current_stock)->toBe(7.0);
});

it('blocks inventory routes on starter plan', function (): void {
    $this->tenant->update(['plan' => 'starter']);

    $this->withToken($this->token)
        ->getJson('/api/v1/inventory/ingredients')
        ->assertStatus(402)
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
