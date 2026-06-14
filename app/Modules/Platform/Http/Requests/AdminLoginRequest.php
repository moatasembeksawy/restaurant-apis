<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class AdminLoginRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
