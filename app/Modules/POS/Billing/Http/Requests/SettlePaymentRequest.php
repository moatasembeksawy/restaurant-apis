<?php

declare(strict_types=1);

namespace App\Modules\POS\Billing\Http\Requests;

use App\Modules\POS\Billing\Models\Payment;
use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SettlePaymentRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $methods = [...Payment::METHODS, ...array_keys(Payment::METHOD_ALIASES)];
        $splitMethods = [...Payment::SPLIT_METHODS, ...array_keys(Payment::METHOD_ALIASES)];

        return [
            'method' => ['required', Rule::in($methods)],
            'amount' => ['required', 'numeric', 'min:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percentage,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'required_with:discount_type'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'splits' => ['required_if:method,split', 'array', 'min:2'],
            'splits.*.method' => ['required', Rule::in($splitMethods)],
            'splits.*.amount' => ['required', 'numeric', 'min:0.01'],
            'splits.*.reference' => ['nullable', 'string', 'max:100'],
            'loyalty_points' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('method') && is_string($this->input('method'))) {
            $payload['method'] = Payment::canonicalizeMethod($this->input('method'));
        }

        $splits = $this->input('splits');

        if (is_array($splits)) {
            $payload['splits'] = array_map(static function (mixed $split): mixed {
                if (is_array($split) && isset($split['method']) && is_string($split['method'])) {
                    $split['method'] = Payment::canonicalizeMethod($split['method']);
                }

                return $split;
            }, $splits);
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
