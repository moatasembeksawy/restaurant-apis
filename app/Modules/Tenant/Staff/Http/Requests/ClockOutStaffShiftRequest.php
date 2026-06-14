<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class ClockOutStaffShiftRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:255'],
            'closing_cash_count' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
