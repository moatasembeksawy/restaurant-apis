<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdatePrintSettingsRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'printing_mode' => ['required', Rule::in(['direct', 'stations'])],
        ];
    }
}
