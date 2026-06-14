<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Tenant\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

it('resolves tenant from custom domain host', function (): void {
    $tenant = Tenant::factory()->create([
        'subdomain' => 'cairo-bistro',
        'custom_domain' => 'orders.cairobistro.com',
        'custom_domain_verified_at' => now(),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_HOST' => 'orders.cairobistro.com',
    ]);

    expect(app(TenantResolver::class)->resolve($request)?->id)->toBe($tenant->id);
});

it('resolves tenant from platform subdomain', function (): void {
    config(['tenant.base_domain' => 'restoapp.eg']);

    $tenant = Tenant::factory()->create(['subdomain' => 'giza-grill']);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_HOST' => 'giza-grill.restoapp.eg',
    ]);

    expect(app(TenantResolver::class)->resolve($request)?->id)->toBe($tenant->id);
});

it('resolves tenant from subdomain header on api host', function (): void {
    $tenant = Tenant::factory()->create(['subdomain' => 'header-test']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'role' => 'waiter',
        'pin' => Hash::make('0000'),
        'is_active' => true,
    ]);

    $this->withServerVariables(['HTTP_HOST' => 'api.restoapp.eg'])
        ->withHeaders(['X-Tenant-Subdomain' => 'header-test'])
        ->postJson('/api/v1/auth/device/login', [
            'branch_id' => $branch->id,
            'pin' => '0000',
            'device_name' => 'tablet',
        ])
        ->assertOk();
});

it('prefers custom domain over subdomain label match', function (): void {
    Tenant::factory()->create(['subdomain' => 'orders']);

    $tenant = Tenant::factory()->create([
        'subdomain' => 'real-tenant',
        'custom_domain' => 'orders.mybrand.com',
        'custom_domain_verified_at' => now(),
    ]);

    $request = Request::create('/test', 'GET', [], [], [], [
        'HTTP_HOST' => 'orders.mybrand.com',
    ]);

    expect(app(TenantResolver::class)->resolve($request)?->id)->toBe($tenant->id);
});

it('resolves tenant for authenticated user regardless of host', function (): void {
    $tenant = Tenant::factory()->create(['subdomain' => 'auth-tenant']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => $user);

    expect(app(TenantResolver::class)->resolve($request)?->id)->toBe($tenant->id);
});
