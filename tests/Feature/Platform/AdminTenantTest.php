<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

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
    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan' => 'enterprise'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'enterprise');

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect($this->tenant->fresh()->status)->toBe('suspended');
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
