<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexBusinessDayRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', 'in:open,closed'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
