<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Models\Subscription;
use App\Modules\Tenant\Subscription\Models\SubscriptionTransaction;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Infrastructure\Fawry\FawryAdapter;
use App\Shared\Infrastructure\Paymob\PaymobAdapter;

beforeEach(function (): void {
    config([
        'services.paymob.hmac_secret' => 'test-hmac-secret',
        'services.paymob.api_key' => 'test-api-key',
        'services.paymob.integration_id' => 123456,
        'services.paymob.iframe_id' => 654321,
        'services.fawry.merchant_code' => 'TEST_MERCHANT',
        'services.fawry.security_key' => 'test-fawry-key',
    ]);

    app()->forgetInstance(PaymobAdapter::class);
    app()->forgetInstance(FawryAdapter::class);
    app()->forgetInstance(SubscriptionService::class);

    $this->tenant = Tenant::factory()->create([
        'plan' => 'starter',
        'status' => 'trial',
        'trial_ends_at' => now()->addDays(7),
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

function buildPaymobPayload(int $tenantId, string $plan, int $amountCents, int $transactionId = 987654): array
{
    return [
        'obj' => [
            'amount_cents' => $amountCents,
            'created_at' => '2026-06-14T10:00:00.000',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => $transactionId,
            'integration_id' => 123456,
            'is_3d_secure' => false,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => [
                'id' => 555,
                'merchant_order_id' => "tenant_{$tenantId}_{$plan}",
            ],
            'owner' => 1,
            'pending' => false,
            'source_data' => [
                'pan' => '2346',
                'sub_type' => 'MasterCard',
                'type' => 'card',
            ],
            'success' => true,
        ],
    ];
}

it('shows current subscription details', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', 'starter')
        ->assertJsonPath('data.status', 'trial')
        ->assertJsonStructure([
            'data' => ['limits', 'available_plans', 'trial_ends_at'],
        ]);
});

it('creates a fawry checkout session for plan upgrade', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/subscription/upgrade', [
            'plan' => 'pro',
            'gateway' => 'fawry',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.gateway', 'fawry')
        ->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.amount_egp', 1999)
        ->assertJsonStructure(['data' => ['charge_request', 'payment_url']]);

    expect(SubscriptionTransaction::query()->where('status', 'pending')->count())->toBe(1);
});

it('activates subscription from paymob webhook', function (): void {
    $payload = buildPaymobPayload($this->tenant->id, 'growth', 99900);
    $hmac = paymobHmac($payload, 'test-hmac-secret');

    $this->postJson('/api/v1/webhook/paymob?hmac='.$hmac, $payload)
        ->assertOk()
        ->assertJsonPath('status', 'activated');

    $tenant = $this->tenant->fresh();
    expect($tenant->plan)->toBe('growth');
    expect($tenant->status)->toBe('active');

    expect(Subscription::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists())->toBeTrue();
    expect(SubscriptionTransaction::query()->where('gateway_transaction_id', '987654')->where('status', 'success')->exists())->toBeTrue();
});

it('rejects paymob webhook with invalid hmac', function (): void {
    $payload = buildPaymobPayload($this->tenant->id, 'pro', 199900);

    $this->postJson('/api/v1/webhook/paymob?hmac=invalid', $payload)
        ->assertBadRequest()
        ->assertJsonPath('status', 'error');
});

it('ignores duplicate paymob webhook transactions', function (): void {
    $payload = buildPaymobPayload($this->tenant->id, 'pro', 199900, 111222);
    $hmac = paymobHmac($payload, 'test-hmac-secret');

    $this->postJson('/api/v1/webhook/paymob?hmac='.$hmac, $payload)->assertOk();
    $this->postJson('/api/v1/webhook/paymob?hmac='.$hmac, $payload)->assertOk();

    expect(Subscription::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('transitions expired trial to grace period via middleware', function (): void {
    $this->tenant->update([
        'status' => 'trial',
        'trial_ends_at' => now()->subDay(),
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'grace_period');
});

it('allows suspended tenants to access subscription upgrade', function (): void {
    $this->tenant->update(['status' => 'suspended']);

    $this->withToken($this->token)
        ->postJson('/api/v1/subscription/upgrade', [
            'plan' => 'growth',
            'gateway' => 'fawry',
        ])
        ->assertOk();
});

it('blocks suspended tenants from pos routes', function (): void {
    $this->tenant->update(['status' => 'suspended']);

    $this->withToken($this->token)
        ->getJson('/api/v1/menu/categories')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'ACCOUNT_SUSPENDED');
});
