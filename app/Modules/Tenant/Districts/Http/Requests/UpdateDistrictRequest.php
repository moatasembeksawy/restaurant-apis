<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Districts\Http\Requests;

use App\Modules\Tenant\Districts\Models\District;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateDistrictRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $district = $this->route('district');
        $districtId = $district instanceof District ? $district->id : $district;
        $branchId = $district instanceof District ? $district->branch_id : null;

        $uniqueName = Rule::unique('districts', 'name')->ignore($districtId);

        if ($tenantId !== null) {
            $uniqueName->where('tenant_id', $tenantId);
        }

        if ($branchId !== null) {
            $uniqueName->where('branch_id', $branchId);
        }

        return [
            'name' => ['sometimes', 'string', 'max:100', $uniqueName],
            'delivery_fee' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
