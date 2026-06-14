<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Services;

use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\Dns\CustomDomainVerifierInterface;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CustomDomainService
{
    public function __construct(private readonly CustomDomainVerifierInterface $verifier) {}

    /** @return array<string, mixed> */
    public function status(Tenant $tenant): array
    {
        if (! $tenant->custom_domain) {
            return [
                'custom_domain' => null,
                'verified' => false,
                'verified_at' => null,
                'instructions' => null,
            ];
        }

        return [
            'custom_domain' => $tenant->custom_domain,
            'verified' => $tenant->custom_domain_verified_at !== null,
            'verified_at' => $tenant->custom_domain_verified_at?->toISOString(),
            'instructions' => $this->instructions($tenant),
        ];
    }

    public function prepareDomain(Tenant $tenant, ?string $domain): void
    {
        if ($domain === null || $domain === '') {
            $tenant->update([
                'custom_domain' => null,
                'custom_domain_verification_token' => null,
                'custom_domain_verified_at' => null,
            ]);

            return;
        }

        $domain = strtolower($domain);

        if ($domain === $tenant->custom_domain && $tenant->custom_domain_verified_at) {
            return;
        }

        $tenant->update([
            'custom_domain' => $domain,
            'custom_domain_verification_token' => Str::random(40),
            'custom_domain_verified_at' => null,
        ]);
    }

    public function verify(Tenant $tenant): Tenant
    {
        if (! $tenant->custom_domain) {
            throw new InvalidArgumentException('No custom domain configured.');
        }

        if (! $tenant->custom_domain_verification_token) {
            throw new InvalidArgumentException('Domain verification token is missing.');
        }

        $cnameTarget = $this->cnameTarget($tenant);
        $txtHost = $this->txtHost($tenant);

        $verified = $this->verifier->cnamePointsTo($tenant->custom_domain, $cnameTarget)
            || $this->verifier->txtRecordMatches($txtHost, $tenant->custom_domain_verification_token);

        if (! $verified) {
            throw new InvalidArgumentException('DNS records not found. Add the CNAME or TXT record and try again.');
        }

        $tenant->update(['custom_domain_verified_at' => now()]);

        AuditLogger::log('tenant.custom_domain_verified', $tenant, [
            'custom_domain' => $tenant->custom_domain,
        ]);

        return $tenant->fresh();
    }

    /** @return array<string, mixed> */
    private function instructions(Tenant $tenant): array
    {
        return [
            'cname' => [
                'host' => $tenant->custom_domain,
                'target' => $this->cnameTarget($tenant),
            ],
            'txt' => [
                'host' => $this->txtHost($tenant),
                'value' => $tenant->custom_domain_verification_token,
            ],
        ];
    }

    private function cnameTarget(Tenant $tenant): string
    {
        return $tenant->subdomain.'.'.config('tenant.base_domain', 'restoapp.eg');
    }

    private function txtHost(Tenant $tenant): string
    {
        return '_restoapp-verify.'.$tenant->custom_domain;
    }
}
