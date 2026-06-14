<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class SettlePaymentRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu,split'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'splits' => ['required_if:method,split', 'array', 'min:2'],
            'splits.*.method' => ['required', 'in:cash,card,vodafone_cash,instapay,meeza,valu'],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.reference' => ['nullable', 'string', 'max:100'],
            'loyalty_points' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
