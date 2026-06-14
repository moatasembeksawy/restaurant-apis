<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreOrderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'floor_table_id' => ['nullable', 'integer'],
            'channel' => ['required', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_type' => ['nullable', 'in:dine_in,takeaway,delivery'],
            'notes' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
