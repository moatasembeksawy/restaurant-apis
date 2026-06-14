<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexStockMovementRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'ingredient_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'in:purchase,waste,adjustment'],
        ], $this->paginationRules());
    }
}
