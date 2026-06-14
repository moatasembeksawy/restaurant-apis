<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Reports\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class CompareBranchReportRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
