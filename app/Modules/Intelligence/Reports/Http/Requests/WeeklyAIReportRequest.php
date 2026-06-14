<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Reports\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class WeeklyAIReportRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'week_start' => ['nullable', 'date'],
            'narrative' => ['nullable', 'boolean'],
        ];
    }
}
