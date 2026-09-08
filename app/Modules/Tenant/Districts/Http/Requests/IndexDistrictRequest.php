<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Districts\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class IndexDistrictRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $branchExists = Rule::exists('branches', 'id');

        if ($tenantId !== null) {
            $branchExists->where('tenant_id', $tenantId);
        }

        return [
            'branch_id' => ['nullable', 'integer', $branchExists],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
