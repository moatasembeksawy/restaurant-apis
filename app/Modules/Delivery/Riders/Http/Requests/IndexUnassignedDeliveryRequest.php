<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexUnassignedDeliveryRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'branch_id' => ['nullable', 'integer'],
        ], $this->paginationRules());
    }
}
