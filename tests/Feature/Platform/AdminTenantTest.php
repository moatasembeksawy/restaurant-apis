<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Platform\Services\TenantManagementService;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Services\StaffService;

beforeEach(function (): void {
    $this->admin = PlatformAdmin::create([
        'name' => 'Platform Admin',
        'email' => 'admin@restoapp.eg',
        'password' => 'password',
        'is_active' => true,
    ]);

    $this->token = $this->admin->createToken('test', ['platform:*'])->plainTextToken;

    $this->tenant = Tenant::factory()->create([
        'plan' => 'growth',
        'status' => 'trial',
        'subdomain' => 'cairo-bistro',
    ]);

    Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
        'email' => 'owner@cairo.eg',
        'is_active' => true,
    ]);
});

it('lists tenants with filters', function (): void {
    Tenant::factory()->create(['plan' => 'pro', 'status' => 'active', 'subdomain' => 'pro-shop']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/tenants?status=trial')
        ->assertOk();

    expect(collect($response->json('data'))->every(fn ($t) => $t['status'] === 'trial'))->toBeTrue();
});

it('shows tenant details for admin', function (): void {
    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/tenants/{$this->tenant->id}")
        ->assertOk();

    expect($response->json('data.subdomain'))->toBe('cairo-bistro');
    expect($response->json('data.owner.email'))->toBe('owner@cairo.eg');
    expect($response->json('data.limits.max_users'))->toBe(5);
});

it('creates a tenant from admin panel', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/tenants', [
            'restaurant_name' => 'Giza Grill',
            'subdomain' => 'giza-grill',
            'owner_name' => 'Ahmed',
            'owner_email' => 'ahmed@giza.eg',
            'owner_password' => 'password123',
            'plan' => 'pro',
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.status', 'active');

    expect(Tenant::query()->where('subdomain', 'giza-grill')->exists())->toBeTrue();
});

it('updates tenant plan and status', function (): void {
    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan' => 'enterprise'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'enterprise');

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect($this->tenant->fresh()->status)->toBe('suspended');
    expect($staff->fresh()->is_active)->toBeFalse();

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($staff->fresh()->is_active)->toBeTrue();
});

it('only reactivates tenant-suspended users when unsuspending', function (): void {
    $owner = User::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('role', 'owner')
        ->first();

    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $manuallyDeactivated = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    app(StaffService::class)->deactivate($manuallyDeactivated, $owner);

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'suspended'])
        ->assertOk();

    expect($staff->fresh()->is_active)->toBeFalse();
    expect($staff->fresh()->deactivation_reason)->toBe('tenant_suspended');
    expect($manuallyDeactivated->fresh()->is_active)->toBeFalse();
    expect($manuallyDeactivated->fresh()->deactivation_reason)->toBe('manual');

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'active'])
        ->assertOk();

    expect($staff->fresh()->is_active)->toBeTrue();
    expect($manuallyDeactivated->fresh()->is_active)->toBeFalse();
});

it('updates tenant feature flags', function (): void {
    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/features", [
            'feature_flags' => ['loyalty', 'ai_reports'],
        ])
        ->assertOk()
        ->assertJsonPath('data.feature_flags', ['loyalty', 'ai_reports']);

    expect($this->tenant->fresh()->hasFeature('loyalty'))->toBeTrue();
});

it('returns platform dashboard stats', function (): void {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk();

    expect($response->json('data.tenants.total'))->toBeGreaterThan(0);
    expect($response->json('data.revenue'))->toHaveKeys(['mrr_egp', 'active_subscriptions']);
});

it('issues impersonation token for tenant owner', function (): void {
    $result = app(TenantManagementService::class)
        ->impersonate($this->tenant, $this->admin);

    expect($result['user']['email'])->toBe('owner@cairo.eg');
    expect($result['impersonated_by']['email'])->toBe('admin@restoapp.eg');
    expect($result['token'])->not->toBeEmpty();

    $this->withToken($result['token'])
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', 'growth');
});

it('issues impersonation token via admin api', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/impersonate")
        ->assertOk()
        ->assertJsonPath('data.user.email', 'owner@cairo.eg')
        ->assertJsonPath('data.impersonated_by.email', 'admin@restoapp.eg')
        ->assertJsonStructure(['data' => ['token', 'expires_at', 'tenant']]);
});
