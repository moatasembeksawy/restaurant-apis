<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Jobs\SendBillingNotificationJob;
use App\Modules\Tenant\Subscription\Models\Subscription;
use App\Modules\Tenant\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
        'email' => 'owner@billing-cycle.test',
        'is_active' => true,
    ]);
});

it('sends renewal reminders before subscription period ends', function (): void {
    config(['billing.renewal_reminder_days' => 3]);

    Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan' => 'growth',
        'status' => 'active',
        'payment_gateway' => 'paymob',
        'gateway_subscription_id' => 'sub-123',
        'amount_cents' => 99900,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDays(2),
    ]);

    $results = app(SubscriptionLifecycleService::class)->run();

    expect($results['renewal_reminders'])->toBe(1);

    Queue::assertPushed(SendBillingNotificationJob::class, fn ($job) => $job->event === 'renewal_reminder');
});

it('moves expired subscriptions into grace period', function (): void {
    $subscription = Subscription::create([
        'tenant_id' => $this->tenant->id,
        'plan' => 'growth',
        'status' => 'active',
        'payment_gateway' => 'paymob',
        'gateway_subscription_id' => 'sub-456',
        'amount_cents' => 99900,
        'current_period_start' => now()->subMonths(2),
        'current_period_end' => now()->subDay(),
    ]);

    $results = app(SubscriptionLifecycleService::class)->run();

    expect($results['expired_subscriptions'])->toBe(1);
    expect($subscription->fresh()->status)->toBe('past_due');
    expect($this->tenant->fresh()->status)->toBe('grace_period');
    expect($this->tenant->fresh()->grace_period_ends_at)->not->toBeNull();

    Queue::assertPushed(SendBillingNotificationJob::class, fn ($job) => $job->event === 'subscription_expired');
});

it('suspends tenants when grace period ends via scheduler', function (): void {
    $this->tenant->update([
        'status' => 'grace_period',
        'grace_period_ends_at' => now()->subDay(),
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $results = app(SubscriptionLifecycleService::class)->run();

    expect($results['billing_transitions'])->toBe(1);
    expect($this->tenant->fresh()->status)->toBe('suspended');
    expect($staff->fresh()->is_active)->toBeFalse();

    Queue::assertPushed(SendBillingNotificationJob::class, fn ($job) => $job->event === 'grace_expired');
});

it('runs billing process command successfully', function (): void {
    Queue::fake();

    $this->artisan('billing:process-subscriptions')
        ->assertSuccessful();
});
