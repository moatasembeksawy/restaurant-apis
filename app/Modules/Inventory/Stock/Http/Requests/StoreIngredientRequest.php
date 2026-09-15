<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreIngredientRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ingredient_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'name_ar' => ['required_without:ingredient_id', 'nullable', 'string', 'max:100'],
            'name_en' => ['nullable', 'string', 'max:100'],
            'unit' => ['required_without:ingredient_id', 'nullable', 'in:kg,g,l,ml,piece'],
            'default_cost' => ['nullable', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
