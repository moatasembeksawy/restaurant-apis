<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreMenuItemRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            'name_ar' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'preparation_time' => ['integer', 'min:1', 'max:120'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}
