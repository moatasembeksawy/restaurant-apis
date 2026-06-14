<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreAdminTenantRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'restaurant_name' => ['required', 'string', 'max:150'],
            'subdomain' => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'locale' => ['nullable', 'in:ar,en'],
            'owner_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', 'min:8', 'max:100'],
            'owner_phone' => ['nullable', 'string', 'max:20'],
            'branch_name' => ['nullable', 'string', 'max:100'],
            'branch_name_ar' => ['nullable', 'string', 'max:100'],
            'branch_address' => ['nullable', 'string', 'max:255'],
            'branch_phone' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'timezone'],
            'plan' => ['nullable', 'in:starter,growth,pro,enterprise'],
            'status' => ['nullable', 'in:active,trial,grace_period,suspended'],
        ];
    }
}
