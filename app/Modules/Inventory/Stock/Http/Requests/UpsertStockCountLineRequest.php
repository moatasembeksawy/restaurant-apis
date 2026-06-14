<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpsertStockCountLineRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ingredient_id' => ['required', 'integer'],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
