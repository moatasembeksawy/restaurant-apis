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
    expect(StockTransfer::query()->count())->toBe(1);
});

it('rejects transfer when destination ingredient is missing', function (): void {
    $this->target->update(['name_ar' => 'بصل']);

    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->id,
            'quantity' => 2,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'STOCK_TRANSFER_FAILED');
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
