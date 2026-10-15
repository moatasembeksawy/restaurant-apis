<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class HubMarkItemReadyRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_order_id' => ['required', 'uuid'],
            'device_id' => ['nullable', 'uuid'],
            'operation_id' => ['nullable', 'uuid'],
        ];
    }
}
