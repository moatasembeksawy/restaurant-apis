<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Models\Subscription;
use App\Modules\Tenant\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    $this->subscription = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan' => 'pro',
        'status' => 'active',
        'payment_gateway' => 'paymob',
        'gateway_subscription_id' => 'sub-cancel-test',
        'amount_cents' => 199900,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDays(10),
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('schedules subscription cancellation at period end', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/cancel')
        ->assertOk()
        ->assertJsonStructure(['data' => ['cancelled_at', 'current_period_end']]);

    expect($this->subscription->fresh()->cancelled_at)->not->toBeNull();
});

it('shows cancelled_at in subscription details', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);

    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription.cancel_at_period_end', true)
        ->assertJsonPath('data.subscription.status', 'active');
});

it('resumes a cancelled subscription', function (): void {
    $this->subscription->update(['cancelled_at' => now()]);

    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/resume')
        ->assertOk()
        ->assertJsonPath('data.cancelled_at', null);

    expect($this->subscription->fresh()->cancelled_at)->toBeNull();
});

it('forbids managers from cancelling subscription', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($manager->createToken('test')->plainTextToken)
        ->postJson('/api/v1/subscription/cancel')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('skips renewal reminders for cancelled subscriptions', function (): void {
    config(['billing.renewal_reminder_days' => 14]);

    $this->subscription->update([
        'cancelled_at' => now(),
        'current_period_end' => now()->addDays(5),
    ]);

    $results = app(SubscriptionLifecycleService::class)->run();

    expect($results['renewal_reminders'])->toBe(0);
});

it('downgrades tenant when a cancelled subscription expires', function (): void {
    $this->subscription->update([
        'cancelled_at' => now()->subDay(),
        'current_period_end' => now()->subDay(),
    ]);

    $results = app(SubscriptionLifecycleService::class)->run();

    expect($results['expired_subscriptions'])->toBe(1);
    expect($this->subscription->fresh()->status)->toBe('cancelled');
    expect($this->tenant->fresh()->plan)->toBe('starter');
    expect($this->tenant->fresh()->status)->toBe('active');
});
