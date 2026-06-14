<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Models\StockMovement;
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

    $this->ingredient = Ingredient::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'current_stock' => 10,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('starts a stock count session', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/inventory/stock-counts', [
            'branch_id' => $this->branch->id,
            'notes' => 'Monthly count',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    expect(StockCount::query()->count())->toBe(1);
});

it('records counted lines and reconciles stock on completion', function (): void {
    $count = StockCount::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->manager->id,
        'status' => 'draft',
    ]);

    $this->withToken($this->token)
        ->putJson("/api/v1/inventory/stock-counts/{$count->id}/lines", [
            'ingredient_id' => $this->ingredient->id,
            'counted_quantity' => 8,
        ])
        ->assertOk()
        ->assertJsonPath('data.lines.0.variance', -2);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/stock-counts/{$count->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect((float) $this->ingredient->fresh()->current_stock)->toBe(8.0);
    expect(
        StockMovement::query()
            ->where('reference_type', StockCount::class)
            ->where('reference_id', $count->id)
            ->where('type', 'adjustment')
            ->exists(),
    )->toBeTrue();
});

it('cancels a draft stock count', function (): void {
    $count = StockCount::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->manager->id,
        'status' => 'draft',
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/stock-counts/{$count->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});
