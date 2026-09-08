<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Districts\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreDistrictRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $uniqueName = Rule::unique('districts', 'name');

        if ($tenantId !== null) {
            $uniqueName->where('tenant_id', $tenantId);
        }

        return [
            'name' => ['required', 'string', 'max:100', $uniqueName],
            'delivery_fee' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
