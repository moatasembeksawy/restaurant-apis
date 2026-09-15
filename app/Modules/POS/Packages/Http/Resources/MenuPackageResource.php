<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Http\Resources;

use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class MenuPackageResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        $package = $this->resource;

        return [
            'photo_url' => $package->photoUrl(),
            'is_sellable' => $package->relationLoaded('slots') ? $package->isSellable() : null,
        ];
    }
}
