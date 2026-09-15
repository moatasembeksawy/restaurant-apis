<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexInventoryStockRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'ingredient_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ], $this->paginationRules());
    }
}
