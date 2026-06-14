<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class DeviceLoginRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'digits:4'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
