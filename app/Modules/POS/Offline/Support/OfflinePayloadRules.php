<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Support;

final class OfflinePayloadRules
{
    /**
     * Shared operation payload fields for cloud sync and the LAN hub.
     *
     * Hub display names (`item_name_ar`) are optional extras so kitchen tickets
     * can render while offline without a catalog lookup.
     *
     * @return array<string, mixed>
     */
    public static function fields(bool $withHubDisplayNames = false): array
    {
        $rules = [
            'operations.*.payload' => ['required', 'array'],
            'operations.*.payload.client_order_id' => ['required', 'uuid'],
            'operations.*.payload.server_order_id' => ['nullable', 'integer'],
            'operations.*.payload.floor_table_id' => ['nullable', 'integer'],
            'operations.*.payload.channel' => ['nullable', 'in:dine_in,qr,whatsapp,talabat,elmenus,own_delivery'],
            'operations.*.payload.fulfillment_type' => ['nullable', 'in:dine_in,takeaway,delivery'],
            'operations.*.payload.notes' => ['nullable', 'string'],
            'operations.*.payload.delivery_address' => ['nullable', 'string', 'max:500'],
            'operations.*.payload.delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'operations.*.payload.customer_id' => ['nullable', 'integer'],
            'operations.*.payload.customer_address_id' => ['nullable', 'integer'],
            'operations.*.payload.district_id' => ['nullable', 'integer'],
            'operations.*.payload.customer_name' => ['nullable', 'string', 'max:100'],
            'operations.*.payload.customer_phone' => ['nullable', 'string', 'max:20'],
            'operations.*.payload.coupon_code' => ['nullable', 'string', 'max:50'],
            'operations.*.payload.status' => ['nullable', 'in:active,cooking,ready,completed,cancelled'],
            'operations.*.payload.client_item_id' => ['nullable', 'uuid'],
            'operations.*.payload.client_payment_id' => ['nullable', 'uuid'],
            'operations.*.payload.method' => ['nullable', 'in:cash'],
            'operations.*.payload.amount' => ['nullable', 'numeric', 'min:0'],
            'operations.*.payload.cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'operations.*.payload.quantity' => ['nullable', 'integer', 'min:1'],
            'operations.*.payload.unit_price' => ['nullable', 'numeric', 'min:0'],
            'operations.*.payload.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.package_id' => ['nullable', 'integer'],
            'operations.*.payload.package_selections' => ['nullable', 'array'],
            'operations.*.payload.package_selections.*.slot_id' => ['nullable', 'integer'],
            'operations.*.payload.package_selections.*.id' => ['nullable', 'integer'],
            'operations.*.payload.package_selections.*.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.package_selections.*.menu_item_ids' => ['nullable', 'array'],
            'operations.*.payload.package_selections.*.menu_item_ids.*' => ['integer'],
            'operations.*.payload.package_selections.*.quantity' => ['nullable', 'integer', 'min:1'],
            'operations.*.payload.items' => ['nullable', 'array', 'min:1'],
            'operations.*.payload.items.*.client_item_id' => ['nullable', 'uuid', 'distinct'],
            'operations.*.payload.items.*.menu_item_id' => [
                'required_without:operations.*.payload.items.*.package_id',
                'nullable',
                'integer',
            ],
            'operations.*.payload.items.*.package_id' => [
                'required_without:operations.*.payload.items.*.menu_item_id',
                'nullable',
                'integer',
            ],
            'operations.*.payload.items.*.quantity' => ['required', 'integer', 'min:1'],
            'operations.*.payload.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'operations.*.payload.items.*.notes' => ['nullable', 'string'],
            'operations.*.payload.items.*.menu_item_ids' => ['nullable', 'array'],
            'operations.*.payload.items.*.menu_item_ids.*' => ['integer'],
            'operations.*.payload.items.*.slots' => ['nullable', 'array'],
            'operations.*.payload.items.*.selections' => ['nullable', 'array'],
            'operations.*.payload.items.*.selections.*.slot_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.selections.*.id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.selections.*.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.selections.*.menu_item_ids' => ['nullable', 'array'],
            'operations.*.payload.items.*.selections.*.menu_item_ids.*' => ['integer'],
            'operations.*.payload.items.*.package_selections' => ['nullable', 'array'],
            'operations.*.payload.items.*.package_selections.*.slot_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections.*.id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections.*.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections.*.menu_item_ids' => ['nullable', 'array'],
            'operations.*.payload.items.*.package_selections.*.menu_item_ids.*' => ['integer'],
            'operations.*.payload.items.*.package_selections.*.quantity' => ['nullable', 'integer', 'min:1'],
            'operations.*.payload.items.*.slots.*.slot_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.slots.*.id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.slots.*.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.slots.*.menu_item_ids' => ['nullable', 'array'],
            'operations.*.payload.items.*.slots.*.menu_item_ids.*' => ['integer'],
        ];

        if ($withHubDisplayNames) {
            $rules['operations.*.payload.item_name_ar'] = ['nullable', 'string'];
            $rules['operations.*.payload.items.*.item_name_ar'] = ['nullable', 'string'];
            $rules['operations.*.payload.items.*.menu_item_id'] = ['nullable', 'integer'];
            $rules['operations.*.payload.items.*.package_id'] = ['nullable', 'integer'];
        }

        return $rules;
    }
}
