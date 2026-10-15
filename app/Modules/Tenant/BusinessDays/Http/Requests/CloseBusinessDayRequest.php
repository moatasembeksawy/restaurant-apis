<?php

declare(strict_types=1);

namespace App\Modules\Tenant\BusinessDays\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class CloseBusinessDayRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
