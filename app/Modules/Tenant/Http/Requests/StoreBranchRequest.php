<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreBranchRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_rate_applies_to' => ['nullable', 'array'],
            'tax_rate_applies_to.*' => ['in:dine_in,takeaway,delivery'],
            'service_charge_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'service_charge_applies_to' => ['nullable', 'array'],
            'service_charge_applies_to.*' => ['in:dine_in,takeaway,delivery'],
        ];
    }
}
