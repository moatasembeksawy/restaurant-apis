<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Modules\POS\Print\Models\Printer;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdatePrinterRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'bridge_key' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'type' => ['sometimes', Rule::in(Printer::TYPES)],
            'paper_width' => ['sometimes', 'integer', Rule::in(Printer::PAPER_WIDTHS)],
            'copies' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'auto_print' => ['sometimes', 'boolean'],
            'is_default_kitchen' => ['sometimes', 'boolean'],
            'is_default_receipt' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
