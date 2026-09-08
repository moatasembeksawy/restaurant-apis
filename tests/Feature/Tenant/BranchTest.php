<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('lists branches for the tenant', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('blocks creating a second branch on starter plan', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/branches', [
            'name' => 'Alex Branch',
            'name_ar' => 'فرع الإسكندرية',
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'PLAN_LIMIT_EXCEEDED');
});

it('allows multiple branches on enterprise plan', function (): void {
    $this->tenant->update(['plan' => 'enterprise']);
    app()->instance('tenant', $this->tenant->fresh());

    $this->withToken($this->token)
        ->postJson('/api/v1/branches', [
            'name' => 'Alex Branch',
            'name_ar' => 'فرع الإسكندرية',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Alex Branch');

    expect(Branch::query()->count())->toBe(2);
});

it('allows a branch to override tenant tax settings', function (): void {
    $this->tenant->update(['plan' => 'enterprise', 'tax_rate' => 14, 'service_charge_rate' => 12]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/branches/{$this->branch->id}", [
            'tax_rate' => 0,
            'tax_rate_applies_to' => ['dine_in'],
            'service_charge_rate' => 10,
            'service_charge_applies_to' => ['dine_in', 'takeaway'],
        ])
        ->assertOk()
        ->assertJsonPath('data.tax_rate', '0.00')
        ->assertJsonPath('data.effective_tax_rate', 0)
        ->assertJsonPath('data.effective_tax_rate_applies_to', ['dine_in'])
        ->assertJsonPath('data.effective_service_charge_rate', 10);

    expect((float) $this->branch->fresh()->tax_rate)->toBe(0.0);
});

it('forbids cashiers from creating branches', function (): void {
    $this->tenant->update(['plan' => 'enterprise']);

    $cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->withToken($cashier->createToken('test')->plainTextToken)
        ->postJson('/api/v1/branches', [
            'name' => 'Blocked Branch',
            'name_ar' => 'فرع',
        ])
        ->assertForbidden();
});
