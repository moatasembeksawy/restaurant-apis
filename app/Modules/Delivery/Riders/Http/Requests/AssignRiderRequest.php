<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class AssignRiderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rider_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
