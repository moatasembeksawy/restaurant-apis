<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Marketing\Jobs;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Marketing\Models\MarketingCampaign;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Intelligence\Marketing\Services\WhatsAppMarketingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMarketingMessageJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly MarketingCampaign $campaign,
        public readonly Customer $customer,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(WhatsAppMarketingService $marketing): void
    {
        app()->instance('tenant', $this->campaign->tenant ?? Tenant::query()->find($this->campaign->tenant_id));

        $marketing->sendToCustomer($this->campaign, $this->customer);
    }
}
