<?php

declare(strict_types=1);

namespace App\Modules\POS\Packages\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexMenuPackageRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer'],
            'available_only' => ['nullable', 'boolean'],
        ];
    }
}
