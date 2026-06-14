<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ETA;

use App\Modules\Tenant\Models\Tenant;

class ETACredentialResolver
{
    public function forTenant(Tenant $tenant): ETACredentials
    {
        $clientId = (string) ($tenant->eta_client_id ?: config('services.eta.client_id', ''));
        $clientSecret = (string) ($tenant->eta_client_secret ?: config('services.eta.client_secret', ''));
        $taxpayerId = (string) ($tenant->eta_taxpayer_id ?: $clientId);
        $branchId = (string) ($tenant->eta_branch_id ?: '0');

        return new ETACredentials(
            clientId: $clientId,
            clientSecret: $clientSecret,
            taxpayerId: $taxpayerId,
            branchId: $branchId,
        );
    }
}
