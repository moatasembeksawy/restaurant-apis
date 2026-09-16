<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateMenuPackageRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer'],
            'name_ar' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description_ar' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'is_available' => ['sometimes', 'boolean'],
            'preparation_time' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'slots' => ['sometimes', 'array', 'min:1'],
            'slots.*.type' => ['required_with:slots', 'in:fixed,choice'],
            'slots.*.menu_item_id' => ['required_if:slots.*.type,fixed', 'nullable', 'integer'],
            'slots.*.quantity' => ['nullable', 'integer', 'min:1'],
            'slots.*.name_ar' => ['nullable', 'string', 'max:150'],
            'slots.*.name_en' => ['nullable', 'string', 'max:150'],
            'slots.*.min_select' => ['nullable', 'integer', 'min:1'],
            'slots.*.max_select' => ['nullable', 'integer', 'min:1'],
            'slots.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'slots.*.options' => ['exclude_unless:slots.*.type,choice', 'required', 'array', 'min:1'],
            'slots.*.options.*.menu_item_id' => ['required', 'integer'],
            'slots.*.options.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'slots.*.options.*.is_available' => ['sometimes', 'boolean'],
            'slots.*.options.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
