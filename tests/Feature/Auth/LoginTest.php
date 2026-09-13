<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'subdomain' => 'test-restaurant',
        'plan' => 'pro',
        'status' => 'active',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'email' => 'owner@test.eg',
        'password' => Hash::make('password'),
        'role' => 'owner',
        'is_active' => true,
    ]);
});

it('allows an owner to login with email and password', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.eg',
        'password' => 'password',
        'device_name' => 'test-web',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'abilities',
                'user' => ['id', 'name', 'role', 'tenant_id'],
                'tenant' => ['id', 'name', 'plan', 'qr_menu_token', 'qr_menu_url'],
            ],
        ])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.tenant.qr_menu_token', $this->branch->fresh()->qr_menu_token);
});

it('rejects invalid credentials', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.eg',
        'password' => 'wrong-password',
        'device_name' => 'test-web',
    ])->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('rejects login for inactive users', function (): void {
    $this->owner->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.eg',
        'password' => 'password',
    ])->assertUnauthorized();
});

it('returns json 401 for unauthenticated api gets without an accept json header', function (): void {
    $this->get('/api/v1/inventory/transfers')
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
});

it('returns me endpoint with correct structure when authenticated', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    app()->instance('tenant', $this->tenant);

    $this->withToken($token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'email', 'role', 'abilities', 'tenant' => ['qr_menu_token', 'qr_menu_url'], 'branch' => ['qr_menu_token', 'qr_menu_url']],
        ])
        ->assertJsonPath('data.tenant.qr_menu_token', $this->branch->qr_menu_token)
        ->assertJsonPath('data.branch.qr_menu_token', $this->branch->qr_menu_token);
});

it('revokes the token on logout', function (): void {
    $tokenResult = $this->owner->createToken('test');
    $plainText = $tokenResult->plainTextToken;
    $tokenId = $tokenResult->accessToken->getKey();

    app()->instance('tenant', $this->tenant);

    $this->withToken($plainText)
        ->deleteJson('/api/v1/auth/token')
        ->assertOk();

    // Token must be deleted from the database
    expect(PersonalAccessToken::find($tokenId))->toBeNull();
});

it('validates required fields on login', function (): void {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
});
