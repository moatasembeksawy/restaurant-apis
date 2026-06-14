<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexIngredientRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'branch_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ], $this->paginationRules());
    }
}
