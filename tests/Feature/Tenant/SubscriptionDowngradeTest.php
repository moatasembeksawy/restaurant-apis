<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Models\Subscription;
use App\Modules\Tenant\Subscription\Services\SubscriptionLifecycleService;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Infrastructure\Fawry\FawryAdapter;

beforeEach(function (): void {
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
        'gateway_subscription_id' => 'sub-downgrade-test',
        'amount_cents' => 199900,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDays(10),
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('schedules a downgrade at period end', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/downgrade', ['plan' => 'growth'])
        ->assertOk()
        ->assertJsonPath('data.pending_plan', 'growth');

    expect($this->subscription->fresh()->pending_plan)->toBe('growth');
    expect($this->subscription->fresh()->cancelled_at)->toBeNull();
});

it('rejects downgrade to same or higher plan', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/downgrade', ['plan' => 'enterprise'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'SUBSCRIPTION_DOWNGRADE_FAILED');
});

it('cancels a pending downgrade', function (): void {
    $this->subscription->update(['pending_plan' => 'growth']);

    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/downgrade/cancel')
        ->assertOk()
        ->assertJsonPath('data.pending_plan', null);

    expect($this->subscription->fresh()->pending_plan)->toBeNull();
});

it('applies pending downgrade when subscription period expires', function (): void {
    $this->subscription->update([
        'pending_plan' => 'growth',
        'current_period_end' => now()->subDay(),
    ]);

    $results = app(SubscriptionLifecycleService::class)->run();

    expect($results['expired_subscriptions'])->toBe(1);
    expect($this->subscription->fresh()->plan)->toBe('growth');
    expect($this->subscription->fresh()->status)->toBe('active');
    expect($this->subscription->fresh()->pending_plan)->toBeNull();
    expect($this->tenant->fresh()->plan)->toBe('growth');
});

it('exposes pending downgrade in subscription details', function (): void {
    $this->subscription->update(['pending_plan' => 'starter']);

    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.subscription.pending_plan', 'starter')
        ->assertJsonPath('data.subscription.downgrade_at_period_end', true);
});

it('allows managers to schedule downgrade', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($manager->createToken('test')->plainTextToken)
        ->postJson('/api/v1/subscription/downgrade', ['plan' => 'growth'])
        ->assertOk();
});

it('clears pending downgrade when initiating upgrade checkout', function (): void {
    $this->subscription->update(['pending_plan' => 'growth']);

    config([
        'services.fawry.merchant_code' => 'TEST_MERCHANT',
        'services.fawry.security_key' => 'test-fawry-key',
    ]);

    app()->forgetInstance(FawryAdapter::class);
    app()->forgetInstance(SubscriptionService::class);

    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/upgrade', [
            'plan' => 'enterprise',
            'gateway' => 'fawry',
        ])
        ->assertOk();

    expect($this->subscription->fresh()->pending_plan)->toBeNull();
});
