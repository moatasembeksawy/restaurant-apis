<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class OpenBusinessDayRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
