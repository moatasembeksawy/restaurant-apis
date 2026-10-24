<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Notifications;

use App\Modules\Tenant\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingDunningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $event,
        public readonly Tenant $tenant,
        public readonly array $context = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $upgradeUrl = url('/api/v1/subscription/upgrade');

        return match ($this->event) {
            'trial_expired' => (new MailMessage)
                ->subject(__('Your RestoApp trial has ended'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('Your trial for :tenant has ended.', ['tenant' => $this->tenant->name]))
                ->line(__('Your account is now in a grace period. Renew soon to avoid suspension.'))
                ->action(__('Renew Subscription'), $upgradeUrl),
            'grace_expired' => (new MailMessage)
                ->subject(__('Your RestoApp account has been suspended'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('The grace period for :tenant has ended.', ['tenant' => $this->tenant->name]))
                ->line(__('POS access is disabled until payment is received.'))
                ->action(__('Renew Subscription'), $upgradeUrl),
            'payment_failed' => (new MailMessage)
                ->subject(__('Subscription payment failed'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('We could not process your subscription payment.'))
                ->line(__('Gateway: :gateway', ['gateway' => $this->context['gateway'] ?? __('unknown')]))
                ->action(__('Try Again'), $upgradeUrl),
            'renewal_reminder' => (new MailMessage)
                ->subject(__('Your RestoApp subscription renews soon'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('Your :tenant subscription renews on :date.', [
                    'tenant' => $this->tenant->name,
                    'date' => $this->context['period_end'] ?? __('soon'),
                ]))
                ->line(__('Plan: :plan', ['plan' => $this->context['plan'] ?? $this->tenant->plan]))
                ->action(__('Renew Subscription'), $upgradeUrl),
            'subscription_expired' => (new MailMessage)
                ->subject(__('Your RestoApp subscription has expired'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('The paid period for :tenant has ended.', ['tenant' => $this->tenant->name]))
                ->line(__('Your account is now in a grace period. Renew to avoid suspension.'))
                ->action(__('Renew Subscription'), $upgradeUrl),
            default => (new MailMessage)
                ->subject(__('Billing notice'))
                ->line(__('Please review your subscription status.')),
        };
    }
}
