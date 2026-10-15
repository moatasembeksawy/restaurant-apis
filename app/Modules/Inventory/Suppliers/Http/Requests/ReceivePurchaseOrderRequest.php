<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class ReceivePurchaseOrderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
