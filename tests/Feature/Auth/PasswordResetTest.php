<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'email' => 'owner@reset.test',
        'role' => 'owner',
        'is_active' => true,
    ]);
});

it('sends password reset notification', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'owner@reset.test',
    ])
        ->assertOk();

    Notification::assertSentTo($this->owner, ResetPassword::class);
});

it('always returns success for unknown email', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'missing@reset.test',
    ])->assertOk();

    Notification::assertNothingSent();
});

it('resets password with valid token', function (): void {
    $token = Password::createToken($this->owner);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'owner@reset.test',
        'token' => $token,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])
        ->assertOk();

    expect(Hash::check('newpassword123', $this->owner->fresh()->password))->toBeTrue();
    expect($this->owner->fresh()->tokens()->count())->toBe(0);
});

it('rejects invalid reset token', function (): void {
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'owner@reset.test',
        'token' => 'invalid-token',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PASSWORD_RESET_FAILED');
});
