<?php

declare(strict_types=1);

namespace App\Modules\Intelligence\Marketing\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class BroadcastMarketingRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_name' => ['required', 'string', 'max:100'],
            'segment' => ['required', 'in:all,inactive_30d,high_spenders,recent_visitors'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['string', 'max:255'],
        ];
    }
}
