<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;

class IndexAuditLogRequest extends ApiFormRequest
{
    use HasPaginationRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'causer_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:255'],
        ], $this->paginationRules());
    }
}
