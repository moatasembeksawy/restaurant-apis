<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexPurchaseOrderRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'status' => ['nullable', 'string', 'max:50'],
        ], $this->paginationRules());
    }
}
