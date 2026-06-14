<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Events;

use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly Subscription $subscription,
        public readonly string $gateway,
    ) {}
}
