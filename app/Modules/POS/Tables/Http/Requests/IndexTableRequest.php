<?php

declare(strict_types=1);

namespace App\Modules\POS\Tables\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexTableRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'section' => ['nullable', 'string', 'max:50'],
        ];
    }
}
