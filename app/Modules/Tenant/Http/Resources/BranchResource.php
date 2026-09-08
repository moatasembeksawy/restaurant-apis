<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Resources;

use App\Modules\POS\Orders\Support\OrderCharges;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class BranchResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        /** @var Tenant|null $tenant */
        $tenant = app()->bound('tenant') ? app('tenant') : $this->resource->tenant;
        $charges = OrderCharges::resolve($tenant, $this->resource);

        return [
            'qr_menu_url' => $this->resource->qrMenuUrl(),
            'effective_tax_rate' => $charges['tax_rate'],
            'effective_service_charge_rate' => $charges['service_charge_rate'],
            'effective_service_charge_applies_to' => $charges['service_charge_applies_to'],
        ];
    }
}
