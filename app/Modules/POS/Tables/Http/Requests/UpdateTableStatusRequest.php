<?php

declare(strict_types=1);

namespace App\Modules\POS\Tables\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UpdateTableStatusRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:free,occupied,reserved,unavailable'],
        ];
    }
}
