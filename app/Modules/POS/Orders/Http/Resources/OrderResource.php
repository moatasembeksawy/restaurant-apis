<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Resources;

use App\Modules\POS\Billing\Http\Resources\PaymentResource;
use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class OrderResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        $district = $this->resource->relationLoaded('district') ? $this->resource->district : null;
        $payment = $this->resource->relationLoaded('payment') ? $this->resource->payment : null;

        if ($payment !== null) {
            $payment->loadMissing('splits');
        }

        return [
            'district_delivery_fee' => $district !== null ? (float) $district->delivery_fee : null,
            'payment' => $payment !== null
                ? (new PaymentResource($payment))->resolve($request)
                : null,
        ];
    }
}
