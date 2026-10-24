<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class MenuItemResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        $item = $this->resource;

        return [
            'photo_url' => $item->photoUrl(),
            'is_sellable' => $item->relationLoaded('parts') ? $item->isSellable() : null,
        ];
    }
}
