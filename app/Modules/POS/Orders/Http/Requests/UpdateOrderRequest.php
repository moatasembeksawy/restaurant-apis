<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateOrderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string'],
        ];
    }
}
