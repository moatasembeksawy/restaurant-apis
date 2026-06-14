<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateAdminTenantFeaturesRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'feature_flags' => ['required', 'array'],
            'feature_flags.*' => ['string', 'max:50'],
        ];
    }
}
