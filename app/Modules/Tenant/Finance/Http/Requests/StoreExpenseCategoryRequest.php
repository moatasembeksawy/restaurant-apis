<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('expense_categories', 'name')
                    ->where('tenant_id', app('tenant')->id),
            ],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('expense_categories', 'code')
                    ->where('tenant_id', app('tenant')->id),
            ],
        ];
    }
}
