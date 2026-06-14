<?php

declare(strict_types=1);

namespace App\Modules\POS\Tables\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class StoreTableRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'capacity' => ['integer', 'min:1', 'max:50'],
            'position_x' => ['integer', 'min:0'],
            'position_y' => ['integer', 'min:0'],
        ];
    }
}
