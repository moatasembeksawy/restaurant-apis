<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Notifications\VerifyEmailNotification;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->unverified()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'email' => 'owner@verify.test',
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('sends verification notification on demand', function (): void {
    Notification::fake();

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/email/verification-notification')
        ->assertOk();

    Notification::assertSentTo($this->owner, VerifyEmailNotification::class);
});

it('verifies email with signed link', function (): void {
    $url = URL::temporarySignedRoute(
        'api.verification.verify',
        now()->addHour(),
        [
            'id' => $this->owner->id,
            'hash' => sha1($this->owner->email),
        ],
    );

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.email', 'owner@verify.test');

    expect($this->owner->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects invalid verification signature', function (): void {
    $this->getJson('/api/v1/auth/verify-email?id='.$this->owner->id.'&hash='.sha1($this->owner->email).'&signature=invalid')
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'EMAIL_VERIFICATION_FAILED');
});

it('returns already verified for confirmed users', function (): void {
    Notification::fake();

    $this->owner->markEmailAsVerified();

    $this->withToken($this->token)
        ->postJson('/api/v1/auth/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('meta.message', 'Email already verified.');

    Notification::assertNothingSent();
});

it('includes email verification status in auth me', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email_verified_at', null);
});
