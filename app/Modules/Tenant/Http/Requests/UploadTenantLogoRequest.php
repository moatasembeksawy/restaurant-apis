<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UploadTenantLogoRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }
}
