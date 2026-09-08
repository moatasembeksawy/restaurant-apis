<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Districts\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexDistrictRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
