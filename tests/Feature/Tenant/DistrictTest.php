<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Districts\Models\District;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    $this->waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('lets staff list districts with their delivery fees', function (): void {
    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name' => 'الدقي',
        'delivery_fee' => 15,
        'sort_order' => 1,
    ]);
    District::factory()->create(); // other tenant

    $response = $this->withToken($this->waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/settings/districts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'الدقي');

    expect((float) $response->json('data.0.delivery_fee'))->toBe(15.0);
});

it('creates a district in settings', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/settings/districts', [
            'branch_id' => $this->branch->id,
            'name' => 'مدينة نصر',
            'delivery_fee' => 25.5,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'مدينة نصر')
        ->assertJsonPath('data.is_active', true);

    expect((float) District::query()->first()->delivery_fee)->toBe(25.5);
});

it('updates a district delivery fee', function (): void {
    $district = District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'delivery_fee' => 10,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/settings/districts/{$district->id}", [
            'delivery_fee' => 18,
        ])
        ->assertOk();

    expect((float) $district->fresh()->delivery_fee)->toBe(18.0);
});

it('blocks waiters from creating districts', function (): void {
    $this->withToken($this->waiter->createToken('test')->plainTextToken)
        ->postJson('/api/v1/settings/districts', [
            'branch_id' => $this->branch->id,
            'name' => 'المعادي',
            'delivery_fee' => 30,
        ])
        ->assertForbidden();
});

it('filters districts by active status', function (): void {
    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name' => 'نشط',
        'is_active' => true,
    ]);
    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name' => 'متوقف',
        'is_active' => false,
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/settings/districts?is_active=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'نشط');
});

it('rejects duplicate district names for the same branch', function (): void {
    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name' => 'الدقي',
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/settings/districts', [
            'branch_id' => $this->branch->id,
            'name' => 'الدقي',
            'delivery_fee' => 20,
        ])
        ->assertUnprocessable();
});

it('allows the same district name with different fees on another branch', function (): void {
    $otherBranch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => false,
    ]);

    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name' => 'الدقي',
        'delivery_fee' => 15,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/settings/districts', [
            'branch_id' => $otherBranch->id,
            'name' => 'الدقي',
            'delivery_fee' => 35,
        ])
        ->assertCreated()
        ->assertJsonPath('data.branch_id', $otherBranch->id);

    expect((float) District::query()->where('branch_id', $otherBranch->id)->first()->delivery_fee)->toBe(35.0);
});

it('lists only districts for the requested branch', function (): void {
    $otherBranch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => false,
    ]);

    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'name' => 'الدقي',
        'delivery_fee' => 15,
    ]);
    District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $otherBranch->id,
        'name' => 'الدقي',
        'delivery_fee' => 40,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/settings/districts?branch_id='.$otherBranch->id)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect((float) $response->json('data.0.delivery_fee'))->toBe(40.0);
});
