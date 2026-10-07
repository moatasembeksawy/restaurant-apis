<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdatePasswordRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed', 'different:current_password'],
        ];
    }
}
