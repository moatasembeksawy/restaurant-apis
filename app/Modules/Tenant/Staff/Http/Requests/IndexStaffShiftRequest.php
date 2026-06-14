<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexStaffShiftRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ];
    }
}
