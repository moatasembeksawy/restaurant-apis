<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Laravel\Sanctum\Sanctum;

it('stores tenant_id on role assignment when a user is created', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'owner',
    ]);

    expect($user->roles)->toHaveCount(1);

    $this->assertDatabaseHas('model_has_roles', [
        'model_id' => $user->id,
        'model_type' => User::class,
        'role_id' => $user->roles->first()->id,
        'tenant_id' => $tenant->id,
    ]);
});

it('scopes permissions to the assigned tenant team', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $manager = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => 'manager',
    ]);

    setPermissionsTeamId($tenantA->id);
    expect($manager->hasPermissionTo('payments.refund', 'web'))->toBeTrue();

    setPermissionsTeamId($tenantB->id);
    $manager = $manager->fresh();
    expect($manager->hasPermissionTo('payments.refund', 'web'))->toBeFalse();
});

it('sets permission team context from tenant middleware during requests', function (): void {
    $tenant = Tenant::factory()->create(['subdomain' => 'perm-team']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    $manager = User::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    Sanctum::actingAs($manager, ['*'], 'sanctum');

    $this->getJson('/api/v1/menu/categories')
        ->assertOk();
});
