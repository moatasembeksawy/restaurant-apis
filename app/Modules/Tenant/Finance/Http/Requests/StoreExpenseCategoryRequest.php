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
        $tenantId = $this->user()?->tenant_id;
        $uniqueName = Rule::unique('expense_categories', 'name');
        $uniqueCode = Rule::unique('expense_categories', 'code');

        if ($tenantId !== null) {
            $uniqueName->where('tenant_id', $tenantId);
            $uniqueCode->where('tenant_id', $tenantId);
        }

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                $uniqueName,
            ],
            'code' => [
                'nullable',
                'string',
                'max:64',
                $uniqueCode,
            ],
        ];
    }
}
