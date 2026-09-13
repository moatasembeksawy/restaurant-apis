<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
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

    $this->source = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'name_ar' => 'طماطم',
        'name_en' => 'Tomato',
        'unit' => 'kg',
        'current_stock' => 20,
    ]);

    $this->target = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchB->id,
        'name_ar' => 'طماطم',
        'name_en' => 'Tomato',
        'unit' => 'kg',
        'current_stock' => 5,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('lists stock transfers', function (): void {
    StockTransfer::create([
        'from_branch_id' => $this->branchA->id,
        'to_branch_id' => $this->branchB->id,
        'from_ingredient_id' => $this->source->id,
        'to_ingredient_id' => $this->target->id,
        'user_id' => $this->manager->id,
        'quantity' => 4,
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
            'ingredient_id' => $this->source->id,
            'quantity' => 4,
        ])
        ->assertCreated();

    expect((float) $response->json('data.from_ingredient.current_stock'))->toBe(16.0);
    expect((float) $response->json('data.to_ingredient.current_stock'))->toBe(9.0);
    expect($response->json('data.created_at_destination'))->toBeFalse();
    expect($this->source->fresh()->catalog_id)->toBe($this->target->fresh()->catalog_id);
    expect(StockTransfer::query()->count())->toBe(1);
});

it('opens a destination stock row when the catalog is missing there', function (): void {
    $this->target->delete();

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->id,
            'quantity' => 2,
        ])
        ->assertCreated();

    expect($response->json('data.created_at_destination'))->toBeTrue();
    expect((float) $response->json('data.to_ingredient.current_stock'))->toBe(2.0);
    expect($response->json('data.to_ingredient.branch_id'))->toBe($this->branchB->id);
    expect($response->json('data.to_ingredient.catalog_id'))->toBe($this->source->fresh()->catalog_id);
    expect(Ingredient::query()->where('branch_id', $this->branchB->id)->count())->toBe(1);
});

it('blocks transfers on pro plan', function (): void {
    $this->tenant->update(['plan' => 'pro']);
    app()->instance('tenant', $this->tenant->fresh());

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->id,
            'quantity' => 1,
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
