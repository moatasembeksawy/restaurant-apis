<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class OrderResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        $district = $this->relationLoaded('district') ? $this->district : null;

        return [
            'district_delivery_fee' => $district !== null ? (float) $district->delivery_fee : null,
        ];
    }
}
