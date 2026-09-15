<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Http\Requests;

use App\Modules\POS\Orders\Support\OrderFulfillment;
use App\Shared\Support\Http\Requests\ApiFormRequest;

class PreviewOfferRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'fulfillment_type' => ['nullable', 'in:'.implode(',', OrderFulfillment::all())],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['nullable', 'integer'],
            'items.*.package_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.category_id' => ['nullable', 'integer'],
        ];
    }
}
