<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Roles\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class SyncRolePermissionsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ];
    }
}
