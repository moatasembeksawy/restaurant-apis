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

    $this->warehouse = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main warehouse']);
    $this->restaurant = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Downtown restaurant']);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->restaurant->id,
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
        'branch_id' => $this->warehouse->id,
        'ingredient_id' => $this->ingredient->id,
        'current_stock' => 20,
        'unit_cost' => 10,
    ]);

    $this->target = InventoryStock::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->restaurant->id,
        'ingredient_id' => $this->ingredient->id,
        'current_stock' => 5,
        'unit_cost' => 4,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

function requestIssueVoucher(mixed $test, float $quantity = 4): int
{
    return $test->withToken($test->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $test->warehouse->id,
            'to_branch_id' => $test->restaurant->id,
            'ingredient_id' => $test->source->ingredient_id,
            'quantity' => $quantity,
        ])
        ->assertCreated()
        ->json('data.id');
}

it('lists stock transfers', function (): void {
    StockTransfer::create([
        'from_branch_id' => $this->warehouse->id,
        'to_branch_id' => $this->restaurant->id,
        'user_id' => $this->manager->id,
        'status' => 'completed',
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/inventory/transfers?status=completed')
        ->assertOk()
        ->assertJsonPath('data.0.from_branch_id', $this->warehouse->id)
        ->assertJsonPath('data.0.to_branch_id', $this->restaurant->id)
        ->assertJsonPath('data.0.from_branch.name', $this->warehouse->name)
        ->assertJsonPath('data.0.status', 'completed');
});

it('creates an issue voucher without moving stock', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->warehouse->id,
            'to_branch_id' => $this->restaurant->id,
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

it('blocks sending before the warehouse prepares the voucher', function (): void {
    $transferId = requestIssueVoucher($this, 4);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'STOCK_TRANSFER_FAILED');

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);
    expect(StockTransfer::query()->find($transferId)->status)->toBe('pending');
});

it('prepares, dispatches, then the restaurant confirms receipt', function (): void {
    $transferId = requestIssueVoucher($this, 8);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/prepare")
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing')
        ->assertJsonPath('data.prepared_by_id', $this->manager->id);

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);

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
        ->assertStatus(422);

    expect((float) $this->target->fresh()->current_stock)->toBe(9.0);
});

it('opens restaurant stock when the ingredient is received there for the first time', function (): void {
    $this->target->delete();

    $transferId = requestIssueVoucher($this, 2);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/prepare")
        ->assertOk();

    expect(InventoryStock::query()->where('branch_id', $this->restaurant->id)->count())->toBe(0);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $destination = InventoryStock::query()->where('branch_id', $this->restaurant->id)->first();

    expect($destination)->not->toBeNull();
    expect((float) $destination->current_stock)->toBe(2.0);
    expect((float) $destination->unit_cost)->toBe(10.0);
    expect($destination->ingredient_id)->toBe($this->source->ingredient_id);
});

it('rejects a send that would take warehouse stock below zero', function (): void {
    $transferId = requestIssueVoucher($this, 25);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/prepare")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertStatus(422);

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);
    expect(StockTransfer::query()->find($transferId)->status)->toBe('preparing');
    expect(StockMovement::query()->count())->toBe(0);
});

it('cancels a pending or preparing voucher without moving stock', function (): void {
    $pendingId = requestIssueVoucher($this, 3);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$pendingId}/cancel", ['reason' => 'Changed mind'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'Changed mind');

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);

    $preparingId = requestIssueVoucher($this, 2);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$preparingId}/prepare")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$preparingId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);
});

it('returns warehouse stock when the restaurant rejects a dispatched shipment', function (): void {
    $transferId = requestIssueVoucher($this, 5);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/prepare")
        ->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertOk();

    expect((float) $this->source->fresh()->current_stock)->toBe(15.0);
    expect((float) $this->target->fresh()->current_stock)->toBe(5.0);

    $this->withToken($this->token)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/cancel", ['reason' => 'Wrong items'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect((float) $this->source->fresh()->current_stock)->toBe(20.0);
    expect((float) $this->target->fresh()->current_stock)->toBe(5.0);
    expect(StockMovement::query()->where('type', 'transfer_in')->count())->toBe(1);
    expect(StockTransfer::query()->find($transferId)->cancel_reason)->toBe('Wrong items');
});

it('lets the restaurant request and the warehouse prepare, send, then wait for restaurant receipt', function (): void {
    $warehouseStaff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->warehouse->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    $restaurantStaff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->restaurant->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    $warehouseStaff->givePermissionTo(['inventory.view', 'inventory.manage']);
    $restaurantStaff->givePermissionTo(['inventory.view', 'inventory.manage']);

    $warehouseToken = $warehouseStaff->createToken('test')->plainTextToken;
    $restaurantToken = $restaurantStaff->createToken('test')->plainTextToken;

    $as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $transferId = $as($restaurantToken)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->warehouse->id,
            'to_branch_id' => $this->restaurant->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 2,
        ])
        ->assertCreated()
        ->json('data.id');

    $as($warehouseToken)
        ->postJson('/api/v1/inventory/transfers', [
            'from_branch_id' => $this->warehouse->id,
            'to_branch_id' => $this->restaurant->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 1,
        ])
        ->assertForbidden();

    $as($restaurantToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/prepare")
        ->assertForbidden();

    $as($warehouseToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/prepare")
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');

    $as($restaurantToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertForbidden();

    $as($warehouseToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/send")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_transit');

    $as($warehouseToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/receive")
        ->assertForbidden();

    $as($warehouseToken)
        ->postJson("/api/v1/inventory/transfers/{$transferId}/cancel")
        ->assertForbidden();

    $as($restaurantToken)
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
            'from_branch_id' => $this->warehouse->id,
            'to_branch_id' => $this->restaurant->id,
            'ingredient_id' => $this->source->ingredient_id,
            'quantity' => 1,
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
