<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeviceLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'digits:4'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
