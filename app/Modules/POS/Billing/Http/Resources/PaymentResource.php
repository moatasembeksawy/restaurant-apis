<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Resources;

use App\Modules\POS\Billing\Models\PaymentSplit;
use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class PaymentResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        if (! $this->resource->relationLoaded('splits')) {
            return [];
        }

        return [
            'splits' => $this->resource->splits
                ->map(static fn (PaymentSplit $split): array => [
                    'id' => $split->id,
                    'method' => $split->method,
                    'amount' => $split->amount,
                    'reference' => $split->reference,
                ])
                ->values()
                ->all(),
        ];
    }
}
