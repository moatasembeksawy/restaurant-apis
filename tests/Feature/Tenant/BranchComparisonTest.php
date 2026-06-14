<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
    $this->branchA = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Downtown',
        'is_default' => true,
    ]);
    $this->branchB = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Maadi',
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('compares revenue across branches', function (): void {
    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchA->id,
        'total' => 500.00,
        'created_at' => now(),
    ]);

    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branchB->id,
        'total' => 300.00,
        'created_at' => now(),
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/reports/branches/compare')
        ->assertOk();

    expect($response->json('data.branches'))->toHaveCount(2);
    expect((float) $response->json('data.totals.revenue'))->toBe(800.0);
    expect($response->json('data.leader.branch_id'))->toBe($this->branchA->id);
});

it('blocks branch comparison on pro plan', function (): void {
    $this->tenant->update(['plan' => 'pro']);
    app()->instance('tenant', $this->tenant->fresh());

    $this->withToken($this->token)
        ->getJson('/api/v1/reports/branches/compare')
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
