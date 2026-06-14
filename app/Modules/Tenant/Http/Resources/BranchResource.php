<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class BranchResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        return ['qr_menu_url' => $this->resource->qrMenuUrl()];
    }
}
