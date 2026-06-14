<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexAdminTenantRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'status' => ['nullable', 'in:active,trial,grace_period,suspended'],
            'plan' => ['nullable', 'in:starter,growth,pro,enterprise'],
            'search' => ['nullable', 'string', 'max:100'],
        ], $this->paginationRules());
    }
}
