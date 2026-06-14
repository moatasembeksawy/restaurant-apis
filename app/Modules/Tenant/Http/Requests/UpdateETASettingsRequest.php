<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateETASettingsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'eta_client_id' => ['nullable', 'string', 'max:100'],
            'eta_client_secret' => ['nullable', 'string', 'max:255'],
            'eta_taxpayer_id' => ['nullable', 'string', 'max:100'],
            'eta_branch_id' => ['nullable', 'string', 'max:20'],
        ];
    }
}
