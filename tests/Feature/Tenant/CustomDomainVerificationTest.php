<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\Dns\FakeCustomDomainVerifier;

beforeEach(function (): void {
    FakeCustomDomainVerifier::reset();
    config(['tenant.base_domain' => 'restoapp.eg']);

    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
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

it('returns domain verification instructions after setting custom domain', function (): void {
    $this->withToken($this->token)
        ->patchJson('/api/v1/settings', [
            'custom_domain' => 'menu.mybrand.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.domain.custom_domain', 'menu.mybrand.com')
        ->assertJsonPath('data.domain.verified', false)
        ->assertJsonPath('data.domain.instructions.cname.target', $this->tenant->subdomain.'.restoapp.eg');

    expect($this->tenant->fresh()->custom_domain_verification_token)->not->toBeNull();
});

it('verifies custom domain via txt record', function (): void {
    $this->tenant->update([
        'custom_domain' => 'menu.mybrand.com',
        'custom_domain_verification_token' => 'verify-token-123',
    ]);

    FakeCustomDomainVerifier::$txtRecords['_restoapp-verify.menu.mybrand.com'] = 'verify-token-123';

    $this->withToken($this->token)
        ->postJson('/api/v1/settings/domain/verify')
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    expect($this->tenant->fresh()->custom_domain_verified_at)->not->toBeNull();
});

it('verifies custom domain via cname record', function (): void {
    $this->tenant->update([
        'custom_domain' => 'orders.mybrand.com',
        'custom_domain_verification_token' => 'verify-token-456',
    ]);

    FakeCustomDomainVerifier::$cnames['orders.mybrand.com'] = $this->tenant->subdomain.'.restoapp.eg';

    $this->withToken($this->token)
        ->postJson('/api/v1/settings/domain/verify')
        ->assertOk()
        ->assertJsonPath('data.verified', true);
});

it('rejects domain verification when dns records are missing', function (): void {
    $this->tenant->update([
        'custom_domain' => 'missing.mybrand.com',
        'custom_domain_verification_token' => 'verify-token-789',
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/settings/domain/verify')
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'DOMAIN_VERIFY_FAILED');
});

it('resets verification when custom domain changes', function (): void {
    $this->tenant->update([
        'custom_domain' => 'old.mybrand.com',
        'custom_domain_verification_token' => 'old-token',
        'custom_domain_verified_at' => now(),
    ]);

    $this->withToken($this->token)
        ->patchJson('/api/v1/settings', [
            'custom_domain' => 'new.mybrand.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.domain.verified', false);

    $fresh = $this->tenant->fresh();
    expect($fresh->custom_domain)->toBe('new.mybrand.com');
    expect($fresh->custom_domain_verified_at)->toBeNull();
    expect($fresh->custom_domain_verification_token)->not->toBe('old-token');
});

it('shows domain status endpoint', function (): void {
    $this->tenant->update([
        'custom_domain' => 'status.mybrand.com',
        'custom_domain_verification_token' => 'token',
        'custom_domain_verified_at' => now(),
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/settings/domain')
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.custom_domain', 'status.mybrand.com');
});
