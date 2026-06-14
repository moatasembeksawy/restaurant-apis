<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @template TModel of Model */
abstract class ModelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof Model) {
            return (array) $this->resource;
        }

        return array_merge($this->resource->toArray(), $this->extras($request));
    }

    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        return [];
    }
}
