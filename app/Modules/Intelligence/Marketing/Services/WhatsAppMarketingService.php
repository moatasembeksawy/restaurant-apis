<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Marketing\Services;

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Marketing\Jobs\SendMarketingMessageJob;
use App\Modules\Intelligence\Marketing\Models\MarketingCampaign;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\WhatsAppClient\WhatsAppClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppMarketingService
{
    /** @var list<string> */
    private const SEGMENTS = ['all', 'inactive_30d', 'high_spenders', 'recent_visitors'];

    /**
     * @param  array<string, string>  $parameters
     */
    public function broadcast(
        Tenant $tenant,
        User $creator,
        string $templateName,
        string $segment,
        array $parameters = [],
    ): MarketingCampaign {
        if (! $tenant->hasFeature('whatsapp_marketing')) {
            throw new InvalidArgumentException('WhatsApp marketing requires Enterprise plan.');
        }

        if (! $tenant->whatsapp_phone_number_id) {
            throw new InvalidArgumentException('WhatsApp phone number is not configured for this tenant.');
        }

        if (! in_array($segment, self::SEGMENTS, true)) {
            throw new InvalidArgumentException('Invalid customer segment.');
        }

        $recipients = $this->recipientsForSegment($segment);

        if ($recipients->isEmpty()) {
            throw new InvalidArgumentException('No customers match this segment.');
        }

        $campaign = MarketingCampaign::create([
            'created_by' => $creator->id,
            'template_name' => $templateName,
            'segment' => $segment,
            'recipients_count' => $recipients->count(),
            'status' => 'processing',
            'parameters' => $parameters,
        ]);

        foreach ($recipients as $customer) {
            SendMarketingMessageJob::dispatch($campaign, $customer);
        }

        return $campaign->fresh();
    }

    public function sendToCustomer(MarketingCampaign $campaign, Customer $customer): void
    {
        $tenant = app('tenant');

        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            $this->clientForTenant($tenant)->sendTemplate(
                to: $customer->phone,
                templateName: $campaign->template_name,
                languageCode: config('whatsapp.language', 'ar'),
                parameters: array_values($campaign->parameters ?? []),
            );

            $campaign->increment('sent_count');
        } catch (RuntimeException) {
            $campaign->increment('failed_count');
        }

        $campaign->refresh();

        if ($campaign->sent_count + $campaign->failed_count >= $campaign->recipients_count) {
            $campaign->update([
                'status' => $campaign->failed_count === $campaign->recipients_count ? 'failed' : 'completed',
            ]);
        }
    }

    /** @return Collection<int, Customer> */
    public function recipientsForSegment(string $segment): Collection
    {
        $query = Customer::query()->whereNotNull('phone');

        return match ($segment) {
            'inactive_30d' => $query
                ->where(function (Builder $q): void {
                    $q->whereNull('last_order_at')
                        ->orWhere('last_order_at', '<', now()->subDays(30));
                })
                ->get(),
            'high_spenders' => $query
                ->where('total_spent', '>=', 1000)
                ->get(),
            'recent_visitors' => $query
                ->where('last_order_at', '>=', now()->subDays(7))
                ->get(),
            default => $query->get(),
        };
    }

    /** @return list<string> */
    public function availableSegments(): array
    {
        return self::SEGMENTS;
    }

    private function clientForTenant(Tenant $tenant): WhatsAppClient
    {
        return new WhatsAppClient(
            phoneNumberId: (string) $tenant->whatsapp_phone_number_id,
            accessToken: (string) config('services.whatsapp.access_token'),
            webhookSecret: (string) config('services.whatsapp.webhook_secret'),
            apiVersion: (string) config('whatsapp.api_version', 'v19.0'),
        );
    }
}
