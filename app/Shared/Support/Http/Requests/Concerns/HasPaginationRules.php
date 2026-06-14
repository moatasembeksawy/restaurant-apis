<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Requests\Concerns;

trait HasPaginationRules
{
    /** @return array<string, list<string>> */
    protected function paginationRules(int $default = 25, int $max = 100): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$max],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
