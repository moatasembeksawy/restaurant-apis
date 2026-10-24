<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Requests\Concerns;

use App\Modules\Inventory\Stock\Models\Ingredient;

trait CanonicalizesPartUnits
{
    protected function prepareForValidation(): void
    {
        $parts = $this->input('parts');
        if (! is_array($parts)) {
            return;
        }

        foreach ($parts as $index => $part) {
            if (! is_array($part) || ! is_string($part['unit'] ?? null)) {
                continue;
            }

            $parts[$index]['unit'] = Ingredient::canonicalizeUnit($part['unit']);
        }

        $this->merge(['parts' => $parts]);
    }
}
