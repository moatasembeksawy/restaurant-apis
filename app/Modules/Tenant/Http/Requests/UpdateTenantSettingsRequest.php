<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateTenantSettingsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'locale' => ['sometimes', 'in:ar,en'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:50'],
            'talabat_webhook_secret' => ['nullable', 'string', 'max:255'],
            'elmenus_webhook_secret' => ['nullable', 'string', 'max:255'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'tax_rate_applies_to' => ['sometimes', 'array'],
            'tax_rate_applies_to.*' => ['in:dine_in,takeaway,delivery'],
            'service_charge_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'service_charge_applies_to' => ['sometimes', 'array'],
            'service_charge_applies_to.*' => ['in:dine_in,takeaway,delivery'],
        ];
    }
}
