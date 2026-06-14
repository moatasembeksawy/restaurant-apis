<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Jobs;

use App\Models\User;
use App\Modules\Delivery\WhatsApp\Services\WhatsAppNotificationService;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Notifications\BillingDunningNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBillingNotificationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $event,
        public readonly array $context = [],
    ) {
        $this->onQueue('notifications');
    }

    public function handle(WhatsAppNotificationService $whatsapp): void
    {
        app()->instance('tenant', $this->tenant);

        $owner = User::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->first();

        if (! $owner) {
            return;
        }

        if ($owner->email) {
            $owner->notify(new BillingDunningNotification($this->event, $this->tenant, $this->context));
        }

        if ($owner->phone) {
            $whatsapp->sendBillingAlert(
                phone: $owner->phone,
                event: $this->event,
                tenant: $this->tenant,
                context: $this->context,
            );
        }
    }
}
