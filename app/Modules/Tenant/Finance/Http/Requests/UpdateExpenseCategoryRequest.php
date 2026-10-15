<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Requests;

use App\Modules\Tenant\Finance\Models\ExpenseCategory;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $category = $this->route('expenseCategory');
        $categoryId = $category instanceof ExpenseCategory ? $category->getKey() : $category;

        $uniqueName = Rule::unique('expense_categories', 'name')->ignore($categoryId);
        $uniqueCode = Rule::unique('expense_categories', 'code')->ignore($categoryId);

        if ($tenantId !== null) {
            $uniqueName->where('tenant_id', $tenantId);
            $uniqueCode->where('tenant_id', $tenantId);
        }

        return [
            'name' => ['sometimes', 'string', 'max:100', $uniqueName],
            'code' => ['nullable', 'string', 'max:64', $uniqueCode],
            'is_active' => ['sometimes', 'boolean'],
            'allocates_to_item_cost' => ['sometimes', 'boolean'],
        ];
    }
}
