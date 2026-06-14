<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class CashSummaryReportRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
