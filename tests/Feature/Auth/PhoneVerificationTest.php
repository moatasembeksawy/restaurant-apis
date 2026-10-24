<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Models\PhoneVerificationCode;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\Otp\FakeOtpSender;
use App\Shared\Infrastructure\Otp\OtpSenderInterface;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    config(['phone_verification.enabled' => true]);

    $this->otp = new FakeOtpSender;
    app()->instance(OtpSenderInterface::class, $this->otp);

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'phone' => '01001234567',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->user->createToken('test')->plainTextToken;
});

it('responds 503 while phone verification is disabled', function (): void {
    config(['phone_verification.enabled' => false]);

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/phone/verification-notification')
        ->assertStatus(503)
        ->assertJsonPath('errors.0.code', 'PHONE_VERIFICATION_DISABLED');

    $this->postJson('/api/v1/auth/phone/forgot-password', ['phone' => '01001234567'])
        ->assertStatus(503);

    expect($this->otp->sent)->toBeEmpty();
});

it('sends a code and verifies the phone', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/phone/verification-notification')
        ->assertOk();

    $code = $this->otp->lastCodeFor('+201001234567');
    expect($code)->toMatch('/^\d{6}$/');

    $record = PhoneVerificationCode::query()->sole();
    expect($record->code_hash)->not->toContain($code);

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.phone', '+201001234567');

    expect($this->user->fresh()->hasVerifiedPhone())->toBeTrue();

    $this->withToken($this->token)
        ->getJson('/api/v1/auth/me')
        ->assertJsonPath('data.phone_verified_at', fn ($value) => $value !== null);
});

it('enforces a resend cooldown', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verification-notification')->assertOk();

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/phone/verification-notification')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('errors.0.code', 'PHONE_CODE_COOLDOWN');
});

it('locks the code after too many wrong attempts', function (): void {
    config(['phone_verification.max_attempts' => 3]);

    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verification-notification')->assertOk();
    $code = $this->otp->lastCodeFor('+201001234567');
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verify', ['code' => $wrong])
        ->assertUnprocessable()->assertJsonPath('errors.0.code', 'PHONE_CODE_INVALID');
    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verify', ['code' => $wrong])
        ->assertUnprocessable();
    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verify', ['code' => $wrong])
        ->assertStatus(429)->assertJsonPath('errors.0.code', 'PHONE_CODE_LOCKED');

    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertUnprocessable();

    expect($this->user->fresh()->hasVerifiedPhone())->toBeFalse();
});

it('rejects expired codes', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verification-notification')->assertOk();
    $code = $this->otp->lastCodeFor('+201001234567');

    $this->travel(11)->minutes();

    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PHONE_CODE_INVALID');
});

it('does not accept a code sent to a previous phone number', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verification-notification')->assertOk();
    $code = $this->otp->lastCodeFor('+201001234567');

    $this->user->update(['phone' => '01111111111']);
    $this->app['auth']->forgetGuards();

    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verify', ['code' => $code])
        ->assertUnprocessable();
});

it('reports delivery failures without keeping the code', function (): void {
    $this->otp->shouldFail = true;

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/phone/verification-notification')
        ->assertStatus(502)
        ->assertJsonPath('errors.0.code', 'PHONE_CODE_DELIVERY_FAILED');

    expect(PhoneVerificationCode::query()->count())->toBe(0);
});

it('requires a phone before verifying', function (): void {
    $this->user->update(['phone' => null]);

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/phone/verification-notification')
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PHONE_MISSING');
});

it('resets a password with a code sent to a verified phone', function (): void {
    $this->user->markPhoneAsVerified();

    $this->postJson('/api/v1/auth/phone/forgot-password', ['phone' => '01001234567'])->assertOk();
    $code = $this->otp->lastCodeFor('+201001234567');

    $this->postJson('/api/v1/auth/phone/reset-password', [
        'phone' => '+201001234567',
        'code' => $code,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    expect(Hash::check('new-password', $this->user->fresh()->password))->toBeTrue()
        ->and($this->user->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', ['login' => '01001234567', 'password' => 'new-password'])->assertOk();
});

it('gives the same forgot-password response for unknown or unverified phones', function (string $phone): void {
    $this->postJson('/api/v1/auth/phone/forgot-password', ['phone' => $phone])
        ->assertOk()
        ->assertJsonPath('meta.message', 'If that phone is registered and verified, a reset code has been sent.');

    expect($this->otp->sent)->toBeEmpty();
})->with([
    'unknown' => ['01199999999'],
    'unverified' => ['01001234567'],
]);

it('rejects a password reset with a wrong code', function (): void {
    $this->user->markPhoneAsVerified();
    $this->postJson('/api/v1/auth/phone/forgot-password', ['phone' => '01001234567'])->assertOk();

    $this->postJson('/api/v1/auth/phone/reset-password', [
        'phone' => '01001234567',
        'code' => '0000000',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PHONE_CODE_INVALID');

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('does not let a verification code reset the password', function (): void {
    $this->withToken($this->token)->postJson('/api/v1/auth/phone/verification-notification')->assertOk();
    $code = $this->otp->lastCodeFor('+201001234567');
    $this->user->markPhoneAsVerified();

    $this->postJson('/api/v1/auth/phone/reset-password', [
        'phone' => '01001234567',
        'code' => $code,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnprocessable();
});
