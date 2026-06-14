<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Recipes\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class SyncRecipeRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
