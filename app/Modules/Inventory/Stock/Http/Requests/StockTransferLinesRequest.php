<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StockTransferLinesRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
