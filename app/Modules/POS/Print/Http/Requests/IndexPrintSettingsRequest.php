<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class IndexPrintSettingsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
        ];
    }
}
