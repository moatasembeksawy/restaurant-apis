<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateDeliveryStatusRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:assigned,picked_up,en_route,delivered,cancelled'],
        ];
    }
}
