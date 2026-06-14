<?php

declare(strict_types=1);

namespace App\Shared\Support\Tenant;

use App\Models\User;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Http\Request;

class TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->tenant;
        }

        $host = strtolower($request->getHost());

        $tenant = Tenant::query()
            ->where('custom_domain', $host)
            ->whereNotNull('custom_domain_verified_at')
            ->first();

        if ($tenant) {
            return $tenant;
        }

        $header = (string) config('tenant.subdomain_header', 'X-Tenant-Subdomain');

        if ($subdomain = $request->header($header)) {
            return Tenant::query()
                ->where('subdomain', strtolower((string) $subdomain))
                ->first();
        }

        $baseDomain = (string) config('tenant.base_domain', '');

        if ($baseDomain !== '' && str_ends_with($host, '.'.$baseDomain)) {
            $subdomain = substr($host, 0, -strlen('.'.$baseDomain));

            if ($subdomain !== '') {
                return Tenant::query()
                    ->where('subdomain', $subdomain)
                    ->first();
            }
        }

        $parts = explode('.', $host);

        if (count($parts) >= 2) {
            return Tenant::query()
                ->where('subdomain', $parts[0])
                ->first();
        }

        return null;
    }
}
