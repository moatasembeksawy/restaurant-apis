<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreStaffRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:manager,cashier,waiter,cook,rider'],
            'branch_id' => ['required', 'integer'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }
}
