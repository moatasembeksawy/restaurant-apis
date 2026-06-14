<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Services;

use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Jobs\SendBillingNotificationJob;
use App\Modules\Tenant\Subscription\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionLifecycleService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function run(): array
    {
        return [
            'renewal_reminders' => $this->sendRenewalReminders(),
            'expired_subscriptions' => $this->processExpiredSubscriptions(),
            'billing_transitions' => $this->processBillingTransitions(),
        ];
    }

    public function sendRenewalReminders(): int
    {
        $days = (int) config('billing.renewal_reminder_days', 3);
        $count = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereNull('cancelled_at')
            ->whereNull('renewal_reminder_sent_at')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', now())
            ->where('current_period_end', '<=', now()->addDays($days))
            ->with('tenant')
            ->each(function (Subscription $subscription) use (&$count): void {
                $tenant = $subscription->tenant;

                if ($tenant->status !== 'active') {
                    return;
                }

                SendBillingNotificationJob::dispatch($tenant, 'renewal_reminder', [
                    'period_end' => $subscription->current_period_end?->toISOString(),
                    'plan' => $subscription->plan,
                ]);

                $subscription->update(['renewal_reminder_sent_at' => now()]);
                $count++;
            });

        return $count;
    }

    public function processExpiredSubscriptions(): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->with('tenant')
            ->each(function (Subscription $subscription) use (&$count): void {
                if ($subscription->cancelled_at !== null) {
                    DB::transaction(function () use ($subscription): void {
                        $subscription->update(['status' => 'cancelled']);
                        $subscription->tenant->update(['plan' => 'starter']);
                    });

                    $count++;

                    return;
                }

                if ($subscription->pending_plan !== null) {
                    $targetPlan = $subscription->pending_plan;

                    DB::transaction(function () use ($subscription, $targetPlan): void {
                        $amountCents = app(PlanPricingService::class)->amountCents($targetPlan);
                        $periodStart = now();
                        $periodEnd = now()->addMonth();

                        $subscription->update([
                            'plan' => $targetPlan,
                            'amount_cents' => $amountCents,
                            'status' => 'active',
                            'pending_plan' => null,
                            'renewal_reminder_sent_at' => null,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                        ]);

                        $subscription->tenant->update(['plan' => $targetPlan]);
                    });

                    $count++;

                    return;
                }

                DB::transaction(function () use ($subscription): void {
                    $subscription->update(['status' => 'past_due']);
                    $this->subscriptions->enterGracePeriod($subscription->tenant);
                });

                SendBillingNotificationJob::dispatch($subscription->tenant->fresh(), 'subscription_expired', [
                    'period_end' => $subscription->current_period_end?->toISOString(),
                    'plan' => $subscription->plan,
                ]);

                $count++;
            });

        return $count;
    }

    public function processBillingTransitions(): int
    {
        $count = 0;

        Tenant::query()
            ->where(function ($query): void {
                $query->where('status', 'trial')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '<', now());
            })
            ->orWhere(function ($query): void {
                $query->where('status', 'grace_period')
                    ->whereNotNull('grace_period_ends_at')
                    ->where('grace_period_ends_at', '<', now());
            })
            ->each(function (Tenant $tenant) use (&$count): void {
                $before = $tenant->status;
                $this->subscriptions->resolveBillingState($tenant);
                $after = $tenant->fresh()->status;

                if ($before !== $after) {
                    $count++;
                }
            });

        return $count;
    }
}
