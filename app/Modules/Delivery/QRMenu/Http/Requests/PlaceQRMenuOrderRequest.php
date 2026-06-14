<?php

declare(strict_types=1);

namespace App\Modules\Delivery\QRMenu\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class PlaceQRMenuOrderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'table_label' => ['nullable', 'string', 'max:50'],
            'fulfillment_type' => ['nullable', 'in:takeaway,delivery'],
            'delivery_address' => ['required_if:fulfillment_type,delivery', 'nullable', 'string', 'max:500'],
        ];
    }
}
