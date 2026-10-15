<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Inventory\Stock\Models\StockMovement;
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

    $this->ingredient = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name_ar' => 'طماطم',
        'name_en' => 'Tomato',
        'unit' => 'kg',
        'default_cost' => 1.5,
    ]);

    $this->source = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'ingredient_id' => $this->ingredient->id,
        'current_stock' => 20,
        'unit_cost' => 10,
    ]);

    $this->target = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchB->id,
        'ingredient_id' => $this->ingredient->id,
        'current_stock' => 5,
        'unit_cost' => 4,
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
        ->getJson('/api/v1/inventory/transfers?status=completed')
        ->assertOk()
        ->assertJsonPath('data.0.from_branch_id', $this->branchA->id)
        ->assertJsonPath('data.0.to_branch_id', $this->branchB->id)
        ->assertJsonPath('data.0.from_branch.name', $this->branchA->name)
        ->assertJsonPath('data.0.status', 'completed');
});

it('requests a transfer without moving stock', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 4,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.user_id', $this->manager->id)
        ->assertJsonPath('data.items.0.ingredient_id', $this->source->ingredient_id)
        ->assertJsonPath('data.items.0.sent_quantity', null);

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);
    expect((float) $this->target->fresh()->current_stock)->toBe(5.0);
    expect(StockMovement::query()->count())->toBe(0);
});

it('sends then confirms receipt and updates the destination average cost', function (): void {
    $transferId = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 8,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send", [
            'items' => [
                ['ingredient_id' => $this->source->ingredient_id, 'quantity' => 6],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit')
        ->assertJsonPath('data.sent_by_id', $this->manager->id);

    expect((float) $this->source->fresh()->current_stock)->toBe(14.0);
    expect((float) $this->target->fresh()->current_stock)->toBe(5.0);
    expect(StockMovement::query()->where('type', 'transfer_out')->count())->toBe(1);
    expect(StockMovement::query()->where('type', 'transfer_in')->count())->toBe(0);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive", [
            'items' => [
                ['ingredient_id' => $this->source->ingredient_id, 'quantity' => 4],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.received_by_id', $this->manager->id);

    expect((float) $this->source->fresh()->current_stock)->toBe(14.0);
    expect((float) $this->target->fresh()->current_stock)->toBe(9.0);
    expect((float) $this->target->fresh()->unit_cost)->toBe(6.6667);
    expect((float) $this->ingredient->fresh()->default_cost)->toBe(1.5);
    expect((float) StockMovement::query()->where('type', 'transfer_in')->value('quantity'))->toBe(4.0);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'STOCK_TRANSFER_FAILED');

    expect((float) $this->target->fresh()->current_stock)->toBe(9.0);
});

it('opens a destination stock row when the ingredient is received there', function (): void {
    $this->target->delete();

    $transferId = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 2,
        ])
        ->assertCreated()
        ->json('data.id');

    expect(InventoryStock::query()->where('branch_id', $this->branchB->id)->count())->toBe(0);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $destination = InventoryStock::query()->where('branch_id', $this->branchB->id)->first();

    expect($destination)->not->toBeNull();
    expect((float) $destination->current_stock)->toBe(2.0);
    expect((float) $destination->unit_cost)->toBe(10.0);
    expect($destination->ingredient_id)->toBe($this->source->ingredient_id);
});

it('rejects a send that would take source stock below zero', function (): void {
    $transferId = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 25,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertStatus(422);

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);
    expect(StockTransfer::query()->find($transferId)->status)->toBe('pending');
    expect(StockMovement::query()->count())->toBe(0);
});

it('cancels a pending request and blocks cancelling a sent transfer', function (): void {
    $transferId = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 3,
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);

    $sentId = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 1,
        ])
        ->json('data.id');

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$sentId}/send")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$sentId}/cancel")
        ->assertStatus(422);

    expect((float) $this->source->fresh()->current_stock)->toBe(19.0);
});

it('lets each branch act only on its step', function (): void {
    $sourceStaff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    $destinationStaff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchB->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    $sourceStaff->givePermissionTo(['inventory.view', 'inventory.manage']);
    $destinationStaff->givePermissionTo(['inventory.view', 'inventory.manage']);

    $sourceToken = $sourceStaff->createToken('test')->plainTextToken;
    $destinationToken = $destinationStaff->createToken('test')->plainTextToken;

    $as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $transferId = $as($destinationToken)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 2,
        ])
        ->assertCreated()
        ->json('data.id');

    $as($sourceToken)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id' => $this->branchB->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 1,
        ])
        ->assertForbidden();

    $as($destinationToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertForbidden();

    $as($sourceToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit');

    $as($sourceToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
        ->assertForbidden();

    $as($destinationToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect((float) $this->source->fresh()->current_stock)->toBe(18.0);
    expect((float) $this->target->fresh()->current_stock)->toBe(7.0);
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
