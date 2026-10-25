<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    config(['sanctum.stateful' => []]);

    $this->tenant = Tenant::factory()->create([
        'plan' => 'pro',
        'status' => 'active',
        'subdomain' => 'roles-cafe',
    ]);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('lists the permission catalog and the system roles', function (): void {
    $permissions = $this->withToken($this->token)
        ->getJson('/api/v1/permissions')
        ->assertOk()
        ->json('data');

    $ordersView = collect($permissions)->firstWhere('name', 'orders.view');

    expect($ordersView)->not->toBeNull()
        ->and($ordersView['group'])->toBe('orders');

    $this->withToken($this->token)
        ->getJson('/api/v1/permissions/'.$ordersView['id'])
        ->assertOk()
        ->assertJsonPath('data.name', 'orders.view');

    $roles = $this->withToken($this->token)
        ->getJson('/api/v1/roles')
        ->assertOk()
        ->json('data');

    expect(collect($roles)->pluck('name')->sort()->values()->all())
        ->toBe(['cashier', 'cook', 'manager', 'owner', 'rider', 'storekeeper', 'waiter']);

    $storekeeper = collect($roles)->firstWhere('name', 'storekeeper');

    expect($storekeeper['is_system'])->toBeTrue()
        ->and($storekeeper['permissions'])->toContain('inventory.manage', 'suppliers.manage', 'branches.view')
        ->and($storekeeper['permissions'])->not->toContain('payments.process', 'orders.create');
});

it('customizes a system role for one restaurant only', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $otherTenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherManager = User::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $managerRole = collect($this->withToken($this->token)->getJson('/api/v1/roles')->json('data'))
        ->firstWhere('name', 'manager');

    $permissions = collect($managerRole['permissions'])
        ->reject(fn (string $name): bool => $name === 'payments.refund')
        ->values()
        ->all();

    $updated = $this->withToken($this->token)
        ->putJson('/api/v1/roles/'.$managerRole['id'].'/permissions', [
            'permissions' => $permissions,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'manager')
        ->assertJsonPath('data.is_system', true)
        ->assertJsonPath('data.is_customized', true)
        ->json('data');

    expect($updated['permissions'])->not->toContain('payments.refund');

    setPermissionsTeamId($this->tenant->id);
    expect($manager->fresh()->hasPermissionTo('payments.refund', 'web'))->toBeFalse()
        ->and($manager->fresh()->hasPermissionTo('orders.view', 'web'))->toBeTrue();

    setPermissionsTeamId($otherTenant->id);
    expect($otherManager->fresh()->hasPermissionTo('payments.refund', 'web'))->toBeTrue();

    expect(
        Role::query()->where('name', 'manager')->whereNull('tenant_id')->first()
            ?->hasPermissionTo('payments.refund'),
    )->toBeTrue();

    $this->withToken($this->token)
        ->deleteJson('/api/v1/roles/'.$updated['id'])
        ->assertNoContent();

    setPermissionsTeamId($this->tenant->id);
    expect($manager->fresh()->hasPermissionTo('payments.refund', 'web'))->toBeTrue();
});

it('creates a custom role and enforces it for staff', function (): void {
    $created = $this->withToken($this->token)
        ->postJson('/api/v1/roles', [
            'name' => 'host',
            'permissions' => ['tables.view'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'host')
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.permissions', ['tables.view'])
        ->json('data');

    $this->withToken($this->token)
        ->postJson('/api/v1/staff', [
            'name' => 'Nour',
            'role' => 'host',
            'branch_id' => $this->branch->id,
            'pin' => '2468',
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'host');

    $login = $this->withHeaders(['X-Tenant-Subdomain' => $this->tenant->subdomain])
        ->postJson('/api/v1/auth/device/login', [
            'branch_id' => $this->branch->id,
            'pin' => '2468',
            'device_name' => 'host-tablet',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.role', 'host');

    expect($login->json('data.abilities'))->toContain('tables.view');

    $hostToken = $login->json('data.token');
    $call = function (string $token) {
        $this->flushSession();
        auth()->forgetGuards();

        return $this->withToken($token);
    };

    $call($hostToken)
        ->getJson('/api/v1/tables')
        ->assertOk();

    $call($hostToken)
        ->getJson('/api/v1/menu/categories')
        ->assertForbidden();

    $ordersView = Permission::query()->where('name', 'orders.view')->firstOrFail();

    $call($this->token)
        ->postJson('/api/v1/roles/'.$created['id'].'/permissions', [
            'permissions' => ['orders.view'],
        ])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['orders.view', 'tables.view']);

    $call($this->token)
        ->deleteJson('/api/v1/roles/'.$created['id'].'/permissions/'.$ordersView->id)
        ->assertOk()
        ->assertJsonPath('data.permissions', ['tables.view']);

    $call($this->token)
        ->patchJson('/api/v1/roles/'.$created['id'], [
            'name' => 'host_lead',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'host_lead');

    expect(User::query()->where('name', 'Nour')->first()?->role)->toBe('host_lead');

    $call($this->token)
        ->deleteJson('/api/v1/roles/'.$created['id'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'ROLE_INVALID');
});

it('rejects owner changes, unknown permissions, and other tenants roles', function (): void {
    $ownerRole = collect($this->withToken($this->token)->getJson('/api/v1/roles')->json('data'))
        ->firstWhere('name', 'owner');

    $this->withToken($this->token)
        ->putJson('/api/v1/roles/'.$ownerRole['id'].'/permissions', [
            'permissions' => ['orders.view'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.message', 'The owner role cannot be changed.');

    $this->withToken($this->token)
        ->deleteJson('/api/v1/roles/'.$ownerRole['id'])
        ->assertStatus(422);

    $this->withToken($this->token)
        ->postJson('/api/v1/roles', [
            'name' => 'floor',
            'permissions' => ['not.a.permission'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'ROLE_INVALID');

    $this->withToken($this->token)
        ->postJson('/api/v1/roles', [
            'name' => 'manager',
            'permissions' => [],
        ])
        ->assertStatus(422);

    $foreign = Role::query()->create([
        'name' => 'foreign_role',
        'guard_name' => 'web',
        'tenant_id' => Tenant::factory()->create()->id,
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/roles/'.$foreign->id)
        ->assertNotFound();
});

it('lets a manager read roles but not change them', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $token = $manager->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/roles')->assertOk();
    $this->withToken($token)->getJson('/api/v1/permissions')->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/roles', [
            'name' => 'host',
            'permissions' => ['tables.view'],
        ])
        ->assertForbidden();
});

it('forbids staff without user access from reading roles', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->withToken($waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/roles')
        ->assertForbidden();
});
