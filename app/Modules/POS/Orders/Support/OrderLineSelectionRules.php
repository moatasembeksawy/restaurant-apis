<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Support;

final class OrderLineSelectionRules
{
    /**
     * Extra fields on a selection object so item parts can carry a chosen
     * dish, an ingredient, and a weight without breaking package payloads.
     *
     * @return array<string, mixed>
     */
    public static function fields(string $prefix = ''): array
    {
        return [
            $prefix.'part_id' => ['nullable', 'integer'],
            $prefix.'ingredient_id' => ['nullable', 'integer'],
            $prefix.'quantity' => ['nullable', 'numeric', 'min:0.0001'],
            $prefix.'weight' => ['nullable', 'numeric', 'min:0.0001'],
            $prefix.'grams' => ['nullable', 'numeric', 'min:0.0001'],
        ];
    }
}
