<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateTenantSettingsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'locale' => ['sometimes', 'in:ar,en'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:50'],
            'talabat_webhook_secret' => ['nullable', 'string', 'max:255'],
            'elmenus_webhook_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
