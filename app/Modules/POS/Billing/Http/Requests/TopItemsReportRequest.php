<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class TopItemsReportRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
