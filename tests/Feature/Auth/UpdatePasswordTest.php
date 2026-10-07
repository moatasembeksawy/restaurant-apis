<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'email' => 'owner@password.test',
        'password' => 'password',
        'role' => 'owner',
        'is_active' => true,
    ]);
});

it('updates the authenticated user password and revokes other sessions', function (): void {
    $current = $this->owner->createToken('current');
    $other = $this->owner->createToken('other');

    $this->withToken($current->plainTextToken)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
        ->assertOk()
        ->assertJsonPath('meta.message', 'Password updated.');

    expect(Hash::check('newpassword123', $this->owner->fresh()->password))->toBeTrue();
    expect(PersonalAccessToken::find($current->accessToken->getKey()))->not->toBeNull();
    expect(PersonalAccessToken::find($other->accessToken->getKey()))->toBeNull();
});

it('allows login with the new password', function (): void {
    $token = $this->owner->createToken('current')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
        ->assertOk();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@password.test',
        'password' => 'newpassword123',
    ])->assertOk();
});

it('rejects an incorrect current password', function (): void {
    $token = $this->owner->createToken('current')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'INVALID_CURRENT_PASSWORD');

    expect(Hash::check('password', $this->owner->fresh()->password))->toBeTrue();
});

it('requires the password confirmation to match', function (): void {
    $token = $this->owner->createToken('current')->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'different123',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.0.field', 'password');
});

it('rejects unauthenticated password updates', function (): void {
    $this->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertUnauthorized();
});
