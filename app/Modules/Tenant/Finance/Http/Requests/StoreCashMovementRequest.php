<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Http\Requests;

use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreCashMovementRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_diff(CashMovement::TYPES, ['reversal']))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
