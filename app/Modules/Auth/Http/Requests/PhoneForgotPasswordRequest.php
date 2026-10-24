<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Phone\ValidPhone;

class PhoneForgotPasswordRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', new ValidPhone(mobileOnly: true)],
        ];
    }
}
