<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Services;

use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Services\SubscriptionService;
use App\Shared\Support\Audit\AuditLogger;
use InvalidArgumentException;

class TenantSettingsService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly CustomDomainService $domains,
    ) {}

    /** @return array<string, mixed> */
    public function show(Tenant $tenant): array
    {
        return [
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'custom_domain' => $tenant->custom_domain,
            'domain' => $this->domains->status($tenant),
            'locale' => $tenant->locale,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'whatsapp_phone_number_id' => $tenant->whatsapp_phone_number_id,
            'has_talabat_webhook_secret' => ! empty($tenant->talabat_webhook_secret),
            'has_elmenus_webhook_secret' => ! empty($tenant->elmenus_webhook_secret),
            'subscription' => $this->subscriptions->currentPlanDetails($tenant),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(Tenant $tenant, array $data, int $updatedByUserId): array
    {
        $updates = [];
        $auditFields = [];

        if (isset($data['name'])) {
            $updates['name'] = $data['name'];
            $auditFields[] = 'name';
        }

        if (isset($data['locale'])) {
            $updates['locale'] = $data['locale'];
            $auditFields[] = 'locale';
        }

        if (array_key_exists('custom_domain', $data)) {
            $domain = $data['custom_domain'];

            if ($domain !== null && $domain !== '') {
                $domain = strtolower((string) $domain);

                if (Tenant::query()
                    ->where('custom_domain', $domain)
                    ->where('id', '!=', $tenant->id)
                    ->exists()) {
                    throw new InvalidArgumentException('This custom domain is already in use.');
                }
            }

            $this->domains->prepareDomain($tenant, $domain ?: null);
            $auditFields[] = 'custom_domain';
        }

        if (array_key_exists('whatsapp_phone_number_id', $data)) {
            $updates['whatsapp_phone_number_id'] = $data['whatsapp_phone_number_id'];
            $auditFields[] = 'whatsapp_phone_number_id';
        }

        if (array_key_exists('talabat_webhook_secret', $data)) {
            $updates['talabat_webhook_secret'] = $data['talabat_webhook_secret'];
            $auditFields[] = 'talabat_webhook_secret';
        }

        if (array_key_exists('elmenus_webhook_secret', $data)) {
            $updates['elmenus_webhook_secret'] = $data['elmenus_webhook_secret'];
            $auditFields[] = 'elmenus_webhook_secret';
        }

        if ($updates !== []) {
            $tenant->update($updates);
        }

        if ($auditFields !== []) {
            AuditLogger::log('tenant.settings_updated', $tenant->fresh(), [
                'updated_by' => $updatedByUserId,
                'fields' => $auditFields,
            ]);
        }

        return $this->show($tenant->fresh());
    }
}
