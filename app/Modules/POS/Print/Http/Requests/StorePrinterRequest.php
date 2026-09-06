<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Modules\POS\Print\Models\Printer;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StorePrinterRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'bridge_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'type' => ['required', Rule::in(Printer::TYPES)],
            'paper_width' => ['nullable', 'integer', Rule::in(Printer::PAPER_WIDTHS)],
            'copies' => ['nullable', 'integer', 'min:1', 'max:5'],
            'auto_print' => ['nullable', 'boolean'],
            'is_default_kitchen' => ['nullable', 'boolean'],
            'is_default_receipt' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
