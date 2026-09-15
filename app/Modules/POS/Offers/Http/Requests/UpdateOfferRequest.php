<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Http\Requests;

use App\Modules\POS\Orders\Support\OrderFulfillment;
use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateOfferRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'target_type' => ['sometimes', 'in:order,category,menu_item,package'],
            'target_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:50'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_types' => ['nullable', 'array'],
            'fulfillment_types.*' => ['in:'.implode(',', OrderFulfillment::all())],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_customer' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'stackable' => ['sometimes', 'boolean'],
        ];
    }
}
