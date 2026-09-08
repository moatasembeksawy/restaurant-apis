<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerAddressRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $districtExists = Rule::exists('districts', 'id')->where('is_active', true);

        if ($tenantId !== null) {
            $districtExists->where('tenant_id', $tenantId);
        }

        return [
            'district_id' => ['required', 'integer', $districtExists],
            'label' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
