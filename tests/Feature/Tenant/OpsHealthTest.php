<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
});

it('returns operational health for owner', function (): void {
    $response = $this->withToken($this->owner->createToken('test')->plainTextToken)
        ->getJson('/api/v1/ops/health')
        ->assertOk();

    expect($response->json('data.database.connected'))->toBeTrue();
    expect($response->json('data.queue.connection'))->not->toBeNull();
    expect($response->json('data.horizon.available'))->toBeTrue();
});

it('forbids non-owners from viewing ops health', function (): void {
    $this->withToken($this->manager->createToken('test')->plainTextToken)
        ->getJson('/api/v1/ops/health')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});
