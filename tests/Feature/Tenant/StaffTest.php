<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
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

it('lists staff for the tenant', function (): void {
    User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'pin' => Hash::make('1234'),
        'is_active' => true,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/staff')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('creates staff with a pin for tablet login', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/staff', [
            'name' => 'محمد',
            'role' => 'waiter',
            'branch_id' => $this->branch->id,
            'pin' => '4321',
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'waiter')
        ->assertJsonPath('data.has_pin', true);

    expect(User::query()->where('role', 'waiter')->count())->toBe(1);
});

it('enforces max users on starter plan', function (): void {
    User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/staff', [
            'name' => 'Extra Staff',
            'role' => 'waiter',
            'branch_id' => $this->branch->id,
            'pin' => '1111',
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'PLAN_LIMIT_EXCEEDED');
});

it('updates staff pin and role', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/staff/{$waiter->id}", [
            'role' => 'cashier',
            'pin' => '9999',
        ])
        ->assertOk()
        ->assertJsonPath('data.role', 'cashier');
});

it('deactivates staff and revokes access', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $waiter->createToken('tablet')->plainTextToken;

    $this->withToken($this->token)
        ->postJson("/api/v1/staff/{$waiter->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($waiter->fresh()->is_active)->toBeFalse();
    expect($waiter->fresh()->tokens()->count())->toBe(0);
});

it('forbids waiters from managing staff', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->withToken($waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/staff')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});
