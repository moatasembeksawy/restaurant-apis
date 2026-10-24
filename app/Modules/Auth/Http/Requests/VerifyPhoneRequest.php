<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class VerifyPhoneRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{4,10}$/'],
        ];
    }
}
