<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

it('registers a new restaurant with trial and owner token', function (): void {
    $response = $this->postJson('/api/v1/onboarding/register', [
        'restaurant_name' => 'مطعم测试',
        'subdomain' => 'test-cafe',
        'owner_name' => 'Ahmed Owner',
        'owner_email' => 'owner@test-cafe.eg',
        'owner_password' => 'password123',
        'branch_name' => 'Main Branch',
        'branch_name_ar' => 'الفرع الرئيسي',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.tenant.subdomain', 'test-cafe')
        ->assertJsonPath('data.tenant.status', 'trial')
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.branch.name', 'Main Branch')
        ->assertJsonStructure([
            'data' => ['token', 'kitchen_device_secret', 'subscription'],
        ]);

    $tenant = Tenant::query()->where('subdomain', 'test-cafe')->first();
    expect($tenant)->not->toBeNull();
    expect($tenant->trial_ends_at)->not->toBeNull();
    expect(Branch::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(User::query()->where('tenant_id', $tenant->id)->where('role', 'owner')->count())->toBe(1);
});

it('rejects duplicate subdomains', function (): void {
    Tenant::factory()->create(['subdomain' => 'taken']);

    $this->postJson('/api/v1/onboarding/register', [
        'restaurant_name' => 'Another Cafe',
        'subdomain' => 'taken',
        'owner_name' => 'Owner',
        'owner_email' => 'another@example.com',
        'owner_password' => 'password123',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'REGISTRATION_FAILED');
});

it('rejects reserved subdomains', function (): void {
    $this->postJson('/api/v1/onboarding/register', [
        'restaurant_name' => 'API Cafe',
        'subdomain' => 'api',
        'owner_name' => 'Owner',
        'owner_email' => 'api@example.com',
        'owner_password' => 'password123',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'REGISTRATION_FAILED');
});

it('allows owner to log in after registration', function (): void {
    $this->postJson('/api/v1/onboarding/register', [
        'restaurant_name' => 'Login Cafe',
        'subdomain' => 'login-cafe',
        'owner_name' => 'Owner',
        'owner_email' => 'login@example.com',
        'owner_password' => 'password123',
    ])->assertCreated();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'login@example.com',
        'password' => 'password123',
        'device_name' => 'web',
    ])
        ->assertOk()
        ->assertJsonPath('data.user.role', 'owner');
});
