<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateKitchenStationRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'printer_ids' => ['sometimes', 'array'],
            'printer_ids.*' => ['integer', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
