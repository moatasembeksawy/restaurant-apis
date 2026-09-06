<?php

declare(strict_types=1);

namespace App\Modules\POS\Print\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

class ReplacePrintRoutesRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'targets' => ['present', 'array'],
            'targets.*' => ['array'],
            'targets.*.printer_id' => ['nullable', 'integer'],
            'targets.*.kitchen_station_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('targets', []) as $index => $target) {
                $hasPrinter = ! empty($target['printer_id']);
                $hasStation = ! empty($target['kitchen_station_id']);

                if ($hasPrinter === $hasStation) {
                    $validator->errors()->add(
                        "targets.{$index}",
                        'Choose exactly one printer_id or kitchen_station_id.',
                    );
                }
            }
        });
    }
}
