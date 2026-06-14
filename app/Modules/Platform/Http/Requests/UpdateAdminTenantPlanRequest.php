<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateAdminTenantPlanRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
        ];
    }
}
