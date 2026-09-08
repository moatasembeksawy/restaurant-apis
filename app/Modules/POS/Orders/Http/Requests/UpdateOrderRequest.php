<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateOrderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'floor_table_id' => ['nullable', 'integer'],
            'channel' => ['sometimes', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_type' => ['sometimes', 'in:dine_in,takeaway,delivery'],
            'notes' => ['nullable', 'string'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
