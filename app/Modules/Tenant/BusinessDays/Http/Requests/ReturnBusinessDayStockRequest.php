<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class ReturnBusinessDayStockRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'return_remaining' => ['nullable', 'boolean'],
            'items' => ['required_without:return_remaining', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
