<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Loyalty\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class RedeemLoyaltyRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
