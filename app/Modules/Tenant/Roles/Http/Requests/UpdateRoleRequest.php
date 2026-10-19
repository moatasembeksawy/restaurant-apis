<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Roles\Http\Requests;

use App\Shared\Support\Authorization\SystemRoles;
use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateRoleRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'regex:'.SystemRoles::NAME_PATTERN],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ];
    }
}
