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
        'logo_url' => 'https://cdn.example.com/logo.png',
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
                'user' => ['id', 'name', 'role', 'permissions', 'tenant_id'],
                'tenant' => ['id', 'name', 'logo_url', 'plan', 'qr_menu_token', 'qr_menu_url'],
            ],
        ])
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonPath('data.user.permissions', fn ($permissions) => is_array($permissions) && in_array('staff.manage', $permissions, true))
        ->assertJsonPath('data.tenant.logo_url', 'https://cdn.example.com/logo.png')
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

it('uses the requested language for API and validation messages', function (): void {
    $this->withHeader('Accept-Language', 'ar-EG,ar;q=0.9,en;q=0.8')
        ->postJson('/api/v1/auth/login', [
            'email' => 'owner@test.eg',
            'password' => 'wrong-password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.message', 'بيانات الدخول غير صحيحة.');

    $this->withHeader('Accept-Language', 'ar')
        ->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.message', 'حقل البريد الإلكتروني أو رقم الهاتف مطلوب.');
});

it('allows login with email via the login field', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'login' => 'owner@test.eg',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.id', $this->owner->id);
});

it('allows login with phone in local or international format', function (string $input): void {
    $this->owner->update(['phone' => '01001234567']);

    $this->postJson('/api/v1/auth/login', [
        'login' => $input,
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('data.user.id', $this->owner->id);
})->with(['01001234567', '+201001234567', '201001234567', '010 0123 4567']);

it('matches a phone stored in international format', function (): void {
    $this->owner->update(['phone' => '+201001234567']);

    $this->postJson('/api/v1/auth/login', [
        'login' => '01001234567',
        'password' => 'password',
    ])->assertOk();
});

it('rejects phone login with the wrong password', function (): void {
    $this->owner->update(['phone' => '01001234567']);

    $this->postJson('/api/v1/auth/login', [
        'login' => '01001234567',
        'password' => 'wrong-password',
    ])->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('normalizes phones when saving users', function (): void {
    $this->owner->update(['phone' => '010 0123-4567']);

    expect($this->owner->fresh()->phone)->toBe('+201001234567');
});

it('throttles repeated failed logins for the same identifier', function (): void {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/login', [
            'login' => 'owner@test.eg',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', [
        'login' => 'OWNER@test.eg',
        'password' => 'password',
    ])->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('errors.0.code', 'TOO_MANY_ATTEMPTS');
});

it('checks a hash even when no account matches', function (): void {
    $realHash = Hash::make('unused');
    Hash::shouldReceive('make')->andReturn($realHash);
    Hash::shouldReceive('check')->once()->andReturnFalse();

    $this->postJson('/api/v1/auth/login', [
        'login' => 'nobody@test.eg',
        'password' => 'password',
    ])->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
});

it('rejects a login value that is neither email nor phone', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'login' => 'not-a-phone',
        'password' => 'password',
    ])->assertUnprocessable();
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
            'data' => ['id', 'name', 'email', 'role', 'permissions', 'abilities', 'tenant' => ['logo_url', 'qr_menu_token', 'qr_menu_url'], 'branch' => ['qr_menu_token', 'qr_menu_url']],
        ])
        ->assertJsonPath('data.tenant.logo_url', 'https://cdn.example.com/logo.png')
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
