<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class CustomerResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        if (! $this->resource->relationLoaded('addresses')) {
            return [];
        }

        return [
            'addresses' => CustomerAddressResource::collection($this->resource->addresses)->resolve($request),
        ];
    }
}
