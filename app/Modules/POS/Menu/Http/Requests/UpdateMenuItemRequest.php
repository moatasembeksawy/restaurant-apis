<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Requests;

use App\Modules\POS\Menu\Http\Requests\Concerns\CanonicalizesPartUnits;
use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateMenuItemRequest extends ApiFormRequest
{
    use CanonicalizesPartUnits;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_ar' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'preparation_time' => ['sometimes', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'integer'],
            'parts' => ['sometimes', 'array'],
            'parts.*.type' => ['required', 'in:fixed,choice,swap,weight'],
            'parts.*.name_ar' => ['nullable', 'string', 'max:150'],
            'parts.*.name_en' => ['nullable', 'string', 'max:150'],
            'parts.*.menu_item_id' => ['nullable', 'integer'],
            'parts.*.default_menu_item_id' => ['nullable', 'integer'],
            'parts.*.ingredient_id' => ['required_if:parts.*.type,weight', 'nullable', 'integer'],
            'parts.*.quantity' => ['nullable', 'integer', 'min:1'],
            'parts.*.included_quantity' => ['required_if:parts.*.type,weight', 'nullable', 'numeric', 'min:0.0001'],
            'parts.*.unit' => ['required_if:parts.*.type,weight', 'nullable', 'in:g,kg'],
            'parts.*.price_per_unit' => ['required_if:parts.*.type,weight', 'nullable', 'numeric', 'min:0'],
            'parts.*.min_select' => ['nullable', 'integer', 'min:0'],
            'parts.*.max_select' => ['nullable', 'integer', 'min:0'],
            'parts.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'parts.*.options' => ['nullable', 'array'],
            'parts.*.options.*.menu_item_id' => ['nullable', 'integer'],
            'parts.*.options.*.ingredient_id' => ['nullable', 'integer'],
            'parts.*.options.*.extra_price' => ['nullable', 'numeric'],
            'parts.*.options.*.price_per_unit' => ['nullable', 'numeric', 'min:0'],
            'parts.*.options.*.is_available' => ['sometimes', 'boolean'],
            'parts.*.options.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
