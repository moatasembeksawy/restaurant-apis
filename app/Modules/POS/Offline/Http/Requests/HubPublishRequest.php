<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Http\Requests;

use App\Modules\POS\Offline\Support\OfflineOperations;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

class HubPublishRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'role' => ['required', 'in:pos,kitchen'],
            'operations' => ['required', 'array', 'min:1', 'max:100'],
            'operations.*.operation_id' => ['required', 'uuid', 'distinct'],
            'operations.*.type' => ['required', 'in:'.implode(',', OfflineOperations::ALL)],
            'operations.*.occurred_at' => ['required', 'date'],
            'operations.*.payload' => ['required', 'array'],
            'operations.*.payload.client_order_id' => ['required', 'uuid'],
            'operations.*.payload.client_item_id' => ['nullable', 'uuid'],
            'operations.*.payload.status' => ['nullable', 'in:active,cooking,ready,completed,cancelled'],
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
            'operations.*.payload.item_name_ar' => ['nullable', 'string'],
            'operations.*.payload.notes' => ['nullable', 'string'],
            'operations.*.payload.items' => ['nullable', 'array', 'min:1'],
            'operations.*.payload.items.*.client_item_id' => ['nullable', 'uuid', 'distinct'],
            'operations.*.payload.items.*.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections' => ['nullable', 'array'],
            'operations.*.payload.items.*.package_selections.*.slot_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections.*.id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections.*.menu_item_id' => ['nullable', 'integer'],
            'operations.*.payload.items.*.package_selections.*.menu_item_ids' => ['nullable', 'array'],
            'operations.*.payload.items.*.package_selections.*.menu_item_ids.*' => ['integer'],
            'operations.*.payload.items.*.package_selections.*.quantity' => ['nullable', 'integer', 'min:1'],
            'operations.*.payload.items.*.quantity' => ['required', 'integer', 'min:1'],
            'operations.*.payload.items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'operations.*.payload.items.*.item_name_ar' => ['nullable', 'string'],
            'operations.*.payload.items.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('operations', []) as $index => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $type = $operation['type'] ?? null;
                $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
                $prefix = "operations.{$index}.payload";

                match ($type) {
                    OfflineOperations::ORDER_CREATED => $this->requireFields($validator, $prefix, $payload, ['items']),
                    OfflineOperations::ITEM_ADDED => $this->requireFields($validator, $prefix, $payload, ['client_item_id', 'quantity']),
                    OfflineOperations::ITEM_UPDATED => $this->requireFields($validator, $prefix, $payload, ['client_item_id', 'quantity']),
                    OfflineOperations::ITEM_REMOVED => $this->requireFields($validator, $prefix, $payload, ['client_item_id']),
                    OfflineOperations::STATUS_CHANGED => $this->requireFields($validator, $prefix, $payload, ['status']),
                    OfflineOperations::ITEM_READY => $this->requireFields($validator, $prefix, $payload, ['client_item_id']),
                    default => null,
                };
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     */
    private function requireFields(Validator $validator, string $prefix, array $payload, array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === []) {
                $validator->errors()->add("{$prefix}.{$field}", "The {$field} field is required.");
            }
        }
    }
}
