<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Requests;

use App\Modules\Tenant\Finance\Models\Expense;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class IndexExpenseRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'expense_category_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Expense::STATUSES)],
            'payment_method' => ['nullable', Rule::in(Expense::PAYMENT_METHODS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
