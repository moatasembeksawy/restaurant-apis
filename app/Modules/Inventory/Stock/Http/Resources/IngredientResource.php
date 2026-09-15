<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Resources;

use App\Modules\Inventory\Stock\Models\InventoryStock;
use App\Modules\Tenant\Models\Branch;
use App\Shared\Support\Http\Resources\ModelResource;
use Illuminate\Http\Request;

class IngredientResource extends ModelResource
{
    /** @return array<string, mixed> */
    protected function extras(Request $request): array
    {
        if (! $this->resource->relationLoaded('stocks')) {
            return [];
        }

        $stocks = $this->resource->stocks;

        return [
            'total_stock' => round((float) $stocks->sum('current_stock'), 3),
            'branch_count' => $stocks->count(),
            'stocks' => $stocks->map(function (InventoryStock $row): array {
                $branch = $row->relationLoaded('branch') && $row->branch instanceof Branch
                    ? $row->branch
                    : null;

                return [
                    'id' => $row->id,
                    'branch_id' => $row->branch_id,
                    'branch_name' => $branch?->name,
                    'branch_name_ar' => $branch?->name_ar,
                    'current_stock' => $row->current_stock,
                    'reorder_level' => $row->reorder_level,
                    'unit_cost' => $row->unit_cost,
                    'is_active' => $row->is_active,
                ];
            })->values()->all(),
        ];
    }
}
