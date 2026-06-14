<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreCustomerRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:100'],
            'default_address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
