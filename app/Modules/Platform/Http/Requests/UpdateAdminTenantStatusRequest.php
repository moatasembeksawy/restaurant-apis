<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateAdminTenantStatusRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,trial,grace_period,suspended'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
