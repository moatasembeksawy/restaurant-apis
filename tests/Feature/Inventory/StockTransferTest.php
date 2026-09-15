<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);

    $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->branchB = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $ingredient = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name_ar' => 'طماطم',
        'name_en' => 'Tomato',
        'unit' => 'kg',
    ]);

    $this->source = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'ingredient_id' => $ingredient->id,
        'current_stock' => 20,
    ]);

    $this->target = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchB->id,
        'ingredient_id' => $ingredient->id,
        'current_stock' => 5,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('lists stock transfers', function (): void {
    StockTransfer::create([
        'from_branch_id' => $this->branchA->id,
        'to_branch_id' => $this->branchB->id,
        'user_id' => $this->manager->id,
        'status' => 'completed',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/inventory/transfers')
        ->assertOk()
        ->assertJsonPath('data.0.from_branch_id', $this->branchA->id)
        ->assertJsonPath('data.0.to_branch_id', $this->branchB->id)
        ->assertJsonPath('data.0.from_branch.name', $this->branchA->name);
});

it('transfers stock between branches', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 4,
        ])
        ->assertCreated();

    expect((float) $response->json('data.from_ingredient.current_stock'))->toBe(16.0);
    expect((float) $response->json('data.to_ingredient.current_stock'))->toBe(9.0);
    expect($response->json('data.created_at_destination'))->toBeFalse();
    expect($this->source->fresh()->ingredient_id)->toBe($this->target->fresh()->ingredient_id);
    expect(StockTransfer::query()->count())->toBe(1);
    expect($response->json('data.transfer.items.0.ingredient_id'))->toBe($this->source->ingredient_id);
});

it('opens a destination stock row when the ingredient is missing there', function (): void {
    $this->target->delete();

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 2,
        ])
        ->assertCreated();

    expect($response->json('data.created_at_destination'))->toBeTrue();
    expect((float) $response->json('data.to_ingredient.current_stock'))->toBe(2.0);
    expect($response->json('data.to_ingredient.branch_id'))->toBe($this->branchB->id);
    expect($response->json('data.to_ingredient.ingredient_id'))->toBe($this->source->fresh()->ingredient_id);
    expect(InventoryStock::query()->where('branch_id', $this->branchB->id)->count())->toBe(1);
});

it('blocks transfers on pro plan', function (): void {
    $this->tenant->update(['plan' => 'pro']);
    app()->instance('tenant', $this->tenant->fresh());

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 1,
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
