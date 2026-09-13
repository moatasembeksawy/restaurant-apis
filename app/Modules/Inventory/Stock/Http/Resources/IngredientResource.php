<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class IngredientResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        $catalog = $this->resource->relationLoaded('catalog') ? $this->resource->catalog : null;

        return [
            'sku' => $catalog?->sku,
        ];
    }
}
