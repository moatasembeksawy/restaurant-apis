<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Analytics\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class CompareAggregatorRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
