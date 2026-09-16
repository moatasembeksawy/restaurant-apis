<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Staff\Models\StaffShift;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'is_default' => true]);
    $this->otherBranch = Branch::factory()->create(['tenant_id' => $this->tenant->id, 'is_default' => false]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);
    $this->waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);
    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 50,
        'is_available' => true,
    ]);
    $this->table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);
    $this->otherTable = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->otherBranch->id,
        'status' => 'free',
    ]);

    app()->instance('tenant', $this->tenant);
});

it('blocks a cashier from clocking in on another branch', function (): void {
    $this->withToken($this->cashier->createToken('test')->plainTextToken)
        ->postJson('/api/v1/staff/shifts/clock-in', [
            'branch_id' => $this->otherBranch->id,
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'BRANCH_ACCESS_DENIED');

    expect(StaffShift::query()->count())->toBe(0);
});

it('lets a cashier clock in on their assigned branch', function (): void {
    $this->withToken($this->cashier->createToken('test')->plainTextToken)
        ->postJson('/api/v1/staff/shifts/clock-in', [
            'branch_id' => $this->branch->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.branch_id', $this->branch->id);
});

it('lets a manager clock in on another branch', function (): void {
    $this->withToken($this->manager->createToken('test')->plainTextToken)
        ->postJson('/api/v1/staff/shifts/clock-in', [
            'branch_id' => $this->otherBranch->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.branch_id', $this->otherBranch->id);
});

it('blocks a waiter from placing an order on another branch', function (): void {
    $this->withToken($this->waiter->createToken('test')->plainTextToken)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->otherBranch->id,
            'floor_table_id' => $this->otherTable->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'BRANCH_ACCESS_DENIED');
});

it('lets a manager place an order on another branch', function (): void {
    $this->withToken($this->manager->createToken('test')->plainTextToken)
        ->postJson('/api/v1/orders', [
            'branch_id' => $this->otherBranch->id,
            'floor_table_id' => $this->otherTable->id,
            'channel' => 'dine_in',
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.branch_id', $this->otherBranch->id);
});

it('hides another branch shift from cashiers listing shifts', function (): void {
    StaffShift::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'clock_in' => now()->subHour(),
    ]);
    StaffShift::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->otherBranch->id,
        'user_id' => $this->manager->id,
        'clock_in' => now()->subHour(),
    ]);

    $response = $this->withToken($this->cashier->createToken('test')->plainTextToken)
        ->getJson('/api/v1/staff/shifts/active')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.branch_id'))->toBe($this->branch->id);
});

it('blocks a waiter from opening an order on another branch', function (): void {
    $foreignOrder = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->otherBranch->id,
        'status' => 'active',
    ]);

    $this->withToken($this->waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/orders/'.$foreignOrder->id)
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'BRANCH_ACCESS_DENIED');
});

it('lets a manager open an order on another branch', function (): void {
    $foreignOrder = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->otherBranch->id,
        'status' => 'active',
    ]);

    $this->withToken($this->manager->createToken('test')->plainTextToken)
        ->getJson('/api/v1/orders/'.$foreignOrder->id)
        ->assertOk()
        ->assertJsonPath('data.id', $foreignOrder->id);
});

it('does not list tables from another branch for waiters', function (): void {
    $response = $this->withToken($this->waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/tables')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($this->table->id);
    expect($ids)->not->toContain($this->otherTable->id);
});

it('blocks a waiter from updating a table on another branch', function (): void {
    $this->withToken($this->waiter->createToken('test')->plainTextToken)
        ->patchJson('/api/v1/tables/'.$this->otherTable->id.'/status', [
            'status' => 'occupied',
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'BRANCH_ACCESS_DENIED');
});
