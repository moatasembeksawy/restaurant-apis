<?php

declare(strict_types=1);

namespace App\Modules\POS\Offers\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexOfferRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'active_only' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
