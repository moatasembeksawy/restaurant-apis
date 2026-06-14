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
                ->subject('Your RestoApp trial has ended')
                ->greeting("Hello {$notifiable->name},")
                ->line("Your trial for {$this->tenant->name} has ended.")
                ->line('Your account is now in a grace period. Renew soon to avoid suspension.')
                ->action('Renew Subscription', $upgradeUrl),
            'grace_expired' => (new MailMessage)
                ->subject('Your RestoApp account has been suspended')
                ->greeting("Hello {$notifiable->name},")
                ->line("The grace period for {$this->tenant->name} has ended.")
                ->line('POS access is disabled until payment is received.')
                ->action('Renew Subscription', $upgradeUrl),
            'payment_failed' => (new MailMessage)
                ->subject('Subscription payment failed')
                ->greeting("Hello {$notifiable->name},")
                ->line('We could not process your subscription payment.')
                ->line('Gateway: '.($this->context['gateway'] ?? 'unknown'))
                ->action('Try Again', $upgradeUrl),
            'renewal_reminder' => (new MailMessage)
                ->subject('Your RestoApp subscription renews soon')
                ->greeting("Hello {$notifiable->name},")
                ->line("Your {$this->tenant->name} subscription renews on ".($this->context['period_end'] ?? 'soon').'.')
                ->line('Plan: '.($this->context['plan'] ?? $this->tenant->plan))
                ->action('Renew Subscription', $upgradeUrl),
            'subscription_expired' => (new MailMessage)
                ->subject('Your RestoApp subscription has expired')
                ->greeting("Hello {$notifiable->name},")
                ->line("The paid period for {$this->tenant->name} has ended.")
                ->line('Your account is now in a grace period. Renew to avoid suspension.')
                ->action('Renew Subscription', $upgradeUrl),
            default => (new MailMessage)
                ->subject('Billing notice')
                ->line('Please review your subscription status.'),
        };
    }
}
