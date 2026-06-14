<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    config(['auth.verification.enforce' => true]);

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->unverified()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('blocks unverified users from pos routes', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/menu/categories')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'EMAIL_NOT_VERIFIED');
});

it('allows unverified users to access subscription and settings', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk();

    $this->withToken($this->token)
        ->getJson('/api/v1/settings')
        ->assertOk();
});

it('allows verified users to access pos routes', function (): void {
    $this->owner->markEmailAsVerified();

    $this->withToken($this->token)
        ->getJson('/api/v1/menu/categories')
        ->assertOk();
});
