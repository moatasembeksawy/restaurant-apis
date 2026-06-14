<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Services;

use App\Models\User;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Events\SubscriptionActivated;
use App\Modules\Tenant\Subscription\Models\Subscription;
use App\Modules\Tenant\Subscription\Models\SubscriptionTransaction;
use App\Shared\Infrastructure\Fawry\FawryAdapter;
use App\Shared\Infrastructure\Paymob\PaymobAdapter;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SubscriptionService
{
    public function __construct(
        private readonly PlanPricingService $pricing,
        private readonly PaymobAdapter $paymob,
        private readonly FawryAdapter $fawry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function currentPlanDetails(Tenant $tenant): array
    {
        $subscription = $tenant->subscription;

        return [
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'limits' => $tenant->planLimits(),
            'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
            'grace_period_ends_at' => $tenant->grace_period_ends_at?->toISOString(),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'plan' => $subscription->plan,
                'status' => $subscription->status,
                'payment_gateway' => $subscription->payment_gateway,
                'amount_egp' => $subscription->amount_cents / 100,
                'current_period_start' => $subscription->current_period_start?->toISOString(),
                'current_period_end' => $subscription->current_period_end?->toISOString(),
            ] : null,
            'available_plans' => collect(config('billing.plans'))
                ->map(fn (array $plan, string $key) => [
                    'key' => $key,
                    'name' => $plan['name'],
                    'name_ar' => $plan['name_ar'],
                    'monthly_egp' => $plan['monthly_egp'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Initiate a plan upgrade/renewal checkout session.
     *
     * @return array<string, mixed>
     */
    public function initiateUpgrade(Tenant $tenant, string $plan, string $gateway, User $initiatedBy): array
    {
        if (! $this->pricing->isValidPlan($plan)) {
            throw new InvalidArgumentException("Invalid plan: {$plan}");
        }

        $amountCents = $this->pricing->amountCents($plan);
        $merchantReference = $this->pricing->merchantReference($tenant->id, $plan);
        $pendingTransactionId = "pending_{$gateway}_{$merchantReference}_".now()->timestamp;

        SubscriptionTransaction::create([
            'tenant_id' => $tenant->id,
            'gateway' => $gateway,
            'gateway_transaction_id' => $pendingTransactionId,
            'merchant_reference' => $merchantReference,
            'plan' => $plan,
            'amount_cents' => $amountCents,
            'status' => 'pending',
        ]);

        if ($gateway === 'paymob') {
            $checkout = $this->paymob->createCheckoutSession(
                amountCents: $amountCents,
                merchantOrderId: $merchantReference,
                billingData: [
                    'email' => $initiatedBy->email ?? 'billing@restoapp.eg',
                    'first_name' => $initiatedBy->name,
                    'last_name' => $tenant->name,
                    'phone_number' => $initiatedBy->phone ?? '01000000000',
                ],
            );

            return [
                'gateway' => 'paymob',
                'plan' => $plan,
                'amount_egp' => $amountCents / 100,
                'merchant_reference' => $merchantReference,
                'checkout_url' => $checkout['checkout_url'],
                'payment_token' => $checkout['payment_token'],
                'paymob_order_id' => $checkout['paymob_order_id'],
            ];
        }

        $charge = $this->fawry->buildChargeRequest(
            merchantReference: $merchantReference,
            amountCents: $amountCents,
            customerMobile: $initiatedBy->phone ?? '01000000000',
            customerEmail: $initiatedBy->email ?? 'billing@restoapp.eg',
            description: "Restaurant SaaS — {$plan} plan",
        );

        return [
            'gateway' => 'fawry',
            'plan' => $plan,
            'amount_egp' => $amountCents / 100,
            'merchant_reference' => $merchantReference,
            'payment_url' => $charge['payment_url'],
            'charge_request' => $charge['charge_request'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processPaymobWebhook(array $payload, ?string $hmac): bool
    {
        if (! $hmac || ! $this->paymob->verifyHmac($payload, $hmac)) {
            throw new RuntimeException('Invalid Paymob HMAC signature.');
        }

        if (! $this->paymob->isSuccessful($payload)) {
            return false;
        }

        $meta = $this->paymob->extractPaymentMeta($payload);

        return $this->activateFromPayment(
            tenantId: $meta['tenant_id'],
            plan: $meta['plan'],
            gateway: 'paymob',
            transactionId: $meta['transaction_id'],
            amountCents: $meta['amount_cents'],
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processFawryWebhook(array $payload): bool
    {
        if (! $this->fawry->verifyCallbackSignature($payload)) {
            throw new RuntimeException('Invalid Fawry signature.');
        }

        if (! $this->fawry->isSuccessful($payload)) {
            return false;
        }

        $meta = $this->fawry->extractPaymentMeta($payload);

        return $this->activateFromPayment(
            tenantId: $meta['tenant_id'],
            plan: $meta['plan'],
            gateway: 'fawry',
            transactionId: $meta['transaction_id'],
            amountCents: $meta['amount_cents'],
            payload: $payload,
        );
    }

    /**
     * Resolve tenant billing state before each authenticated request.
     */
    public function resolveBillingState(Tenant $tenant): Tenant
    {
        if ($tenant->status === 'trial' && $tenant->trial_ends_at?->isPast()) {
            $tenant->update([
                'status' => 'grace_period',
                'grace_period_ends_at' => now()->addDays((int) config('billing.grace_period_days', 7)),
            ]);
            $tenant->refresh();
        }

        if ($tenant->status === 'grace_period' && $tenant->grace_period_ends_at?->isPast()) {
            $tenant->update(['status' => 'suspended']);
            $tenant->refresh();
        }

        return $tenant;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function activateFromPayment(
        int $tenantId,
        string $plan,
        string $gateway,
        string $transactionId,
        int $amountCents,
        array $payload,
    ): bool {
        if (SubscriptionTransaction::query()->where('gateway_transaction_id', $transactionId)->where('status', 'success')->exists()) {
            return true;
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        DB::transaction(function () use ($tenant, $plan, $gateway, $transactionId, $amountCents, $payload): void {
            $periodStart = now();
            $periodEnd = now()->addMonth();

            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'plan' => $plan,
                'status' => 'active',
                'payment_gateway' => $gateway,
                'gateway_subscription_id' => $transactionId,
                'amount_cents' => $amountCents,
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

            $tenant->update([
                'plan' => $plan,
                'status' => 'active',
                'grace_period_ends_at' => null,
            ]);

            SubscriptionTransaction::query()
                ->where('tenant_id', $tenant->id)
                ->where('merchant_reference', $this->pricing->merchantReference($tenant->id, $plan))
                ->where('status', 'pending')
                ->update(['status' => 'failed']);

            SubscriptionTransaction::updateOrCreate(
                ['gateway_transaction_id' => $transactionId],
                [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'gateway' => $gateway,
                    'merchant_reference' => $this->pricing->merchantReference($tenant->id, $plan),
                    'plan' => $plan,
                    'amount_cents' => $amountCents,
                    'status' => 'success',
                    'payload' => $payload,
                ],
            );

            app()->instance('tenant', $tenant->fresh());
            AuditLogger::log('subscription.activated', $subscription, [
                'plan' => $plan,
                'gateway' => $gateway,
                'amount_cents' => $amountCents,
            ]);

            SubscriptionActivated::dispatch($tenant->fresh(), $subscription, $gateway);
        });

        return true;
    }
}
