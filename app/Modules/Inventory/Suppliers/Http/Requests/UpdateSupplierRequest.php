<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Suppliers\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateSupplierRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
