<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\WhatsApp\Services\WhatsAppNotificationService;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Jobs\SendBillingNotificationJob;
use App\Modules\Tenant\Subscription\Notifications\BillingDunningNotification;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Infrastructure\Paymob\PaymobAdapter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'starter',
        'status' => 'trial',
        'trial_ends_at' => now()->addDays(7),
    ]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
        'email' => 'owner@billing.test',
        'phone' => '01012345678',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('dispatches billing notification when trial expires', function (): void {
    Queue::fake();

    $this->tenant->update([
        'status' => 'trial',
        'trial_ends_at' => now()->subDay(),
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'grace_period');

    Queue::assertPushed(SendBillingNotificationJob::class, fn ($job) => $job->event === 'trial_expired');
});

it('dispatches billing notification when grace period expires', function (): void {
    Queue::fake();

    $this->tenant->update([
        'status' => 'grace_period',
        'grace_period_ends_at' => now()->subDay(),
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    Queue::assertPushed(SendBillingNotificationJob::class, fn ($job) => $job->event === 'grace_expired');
});

it('sends billing dunning email to owner', function (): void {
    Notification::fake();

    $job = new SendBillingNotificationJob($this->tenant, 'trial_expired');
    $job->handle(app(WhatsAppNotificationService::class));

    Notification::assertSentTo(
        $this->owner,
        BillingDunningNotification::class,
        fn ($notification) => $notification->event === 'trial_expired',
    );
});

it('dispatches billing notification on failed paymob webhook', function (): void {
    Queue::fake();

    config(['services.paymob.hmac_secret' => 'test-hmac-secret']);

    app()->forgetInstance(SubscriptionService::class);
    app()->forgetInstance(PaymobAdapter::class);

    $payload = [
        'obj' => [
            'amount_cents' => 99900,
            'created_at' => '2026-06-14T10:00:00.000',
            'currency' => 'EGP',
            'error_occured' => true,
            'has_parent_transaction' => false,
            'id' => 555444,
            'integration_id' => 123456,
            'is_3d_secure' => false,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => [
                'id' => 555,
                'merchant_order_id' => "tenant_{$this->tenant->id}_growth",
            ],
            'owner' => 1,
            'pending' => false,
            'source_data' => [
                'pan' => '2346',
                'sub_type' => 'MasterCard',
                'type' => 'card',
            ],
            'success' => false,
        ],
    ];

    $hmac = paymobHmac($payload, 'test-hmac-secret');

    $this->postJson('/api/v1/webhook/paymob?hmac='.$hmac, $payload)
        ->assertOk()
        ->assertJsonPath('status', 'ignored');

    Queue::assertPushed(SendBillingNotificationJob::class, fn ($job) => $job->event === 'payment_failed');
});
