<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Requests;

use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

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
            'unit' => ['required_without:ingredient_id', 'nullable', Rule::in(Ingredient::UNITS)],
            'default_cost' => ['nullable', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('unit') && is_string($this->input('unit'))) {
            $this->merge(['unit' => Ingredient::canonicalizeUnit($this->input('unit'))]);
        }
    }
}
