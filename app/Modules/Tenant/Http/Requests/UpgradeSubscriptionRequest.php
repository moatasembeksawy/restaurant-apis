<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpgradeSubscriptionRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'in:starter,growth,pro,enterprise'],
            'gateway' => ['required', 'in:paymob,fawry'],
        ];
    }
}
