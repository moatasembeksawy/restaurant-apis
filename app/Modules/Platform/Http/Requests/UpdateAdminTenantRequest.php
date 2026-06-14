<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateAdminTenantRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'subdomain' => ['sometimes', 'string', 'max:50', 'alpha_dash'],
            'locale' => ['sometimes', 'in:ar,en'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
        ];
    }
}
