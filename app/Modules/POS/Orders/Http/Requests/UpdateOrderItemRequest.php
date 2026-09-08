<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateOrderItemRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
