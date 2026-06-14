<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexOrderRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'status' => ['nullable', 'in:active,cooking,ready,completed,paid,cancelled'],
            'branch_id' => ['nullable', 'integer'],
            'table_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_type' => ['nullable', 'in:dine_in,takeaway,delivery'],
        ], $this->paginationRules());
    }
}
