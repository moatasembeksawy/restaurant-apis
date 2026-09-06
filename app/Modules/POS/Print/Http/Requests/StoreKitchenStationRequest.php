<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreKitchenStationRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'printer_ids' => ['nullable', 'array'],
            'printer_ids.*' => ['integer', 'distinct'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
