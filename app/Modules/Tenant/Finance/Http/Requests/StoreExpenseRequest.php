<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Requests;

use App\Modules\Tenant\Finance\Models\Expense;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'expense_category_id' => ['required', 'integer'],
            'staff_shift_id' => ['nullable', 'integer', 'required_if:payment_method,cash'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(Expense::PAYMENT_METHODS)],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'receipt_path' => ['nullable', 'string', 'max:500'],
            'expense_date' => ['required', 'date'],
        ];
    }
}
