<?php

declare(strict_types=1);

use App\Modules\Platform\Models\PlatformAdmin;

beforeEach(function (): void {
    $this->admin = PlatformAdmin::create([
        'name' => 'Platform Admin',
        'email' => 'admin@restoapp.eg',
        'password' => 'password',
        'is_active' => true,
    ]);
});

it('logs in platform admin and returns token', function (): void {
    $response = $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'admin@restoapp.eg',
        'password' => 'password',
    ])->assertOk();

    expect($response->json('data.token'))->not->toBeEmpty();
    expect($response->json('data.admin.email'))->toBe('admin@restoapp.eg');
});

it('rejects invalid admin credentials', function (): void {
    $this->postJson('/api/v1/admin/auth/login', [
        'email' => 'admin@restoapp.eg',
        'password' => 'wrong-password',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('returns admin profile for authenticated platform admin', function (): void {
    $token = $this->admin->createToken('test', ['platform:*'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/admin/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@restoapp.eg');
});

it('rejects tenant user tokens on admin routes', function (): void {
    $user = \App\Models\User::factory()->create([
        'role' => 'owner',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/v1/admin/auth/me')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'PLATFORM_ADMIN_REQUIRED');
});
