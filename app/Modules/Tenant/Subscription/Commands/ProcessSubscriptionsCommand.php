<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Subscription\Commands;

use App\Modules\Tenant\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;

class ProcessSubscriptionsCommand extends Command
{
    protected $signature = 'billing:process-subscriptions';

    protected $description = 'Send renewal reminders, expire subscriptions, and process billing state transitions';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $results = $lifecycle->run();

        $this->info(sprintf(
            'Billing processed — reminders: %d, expired: %d, transitions: %d',
            $results['renewal_reminders'],
            $results['expired_subscriptions'],
            $results['billing_transitions'],
        ));

        return self::SUCCESS;
    }
}
