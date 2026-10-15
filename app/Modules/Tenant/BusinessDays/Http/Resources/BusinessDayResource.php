<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class BusinessDayResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        return [
            'is_open' => $this->resource->isOpen(),
            'daily_order_count' => max(0, (int) $this->resource->next_order_number - 1),
        ];
    }
}
