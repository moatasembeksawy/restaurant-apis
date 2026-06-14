<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexRiderRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
