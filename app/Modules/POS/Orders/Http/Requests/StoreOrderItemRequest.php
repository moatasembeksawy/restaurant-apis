<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreOrderItemRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'menu_item_id' => ['required_without:package_id', 'nullable', 'integer'],
            'package_id' => ['required_without:menu_item_id', 'nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'selections' => ['nullable', 'array'],
            'selections.*.slot_id' => ['required', 'integer'],
            'selections.*.menu_item_ids' => ['required', 'array', 'min:1'],
            'selections.*.menu_item_ids.*' => ['integer'],
        ];
    }
}
