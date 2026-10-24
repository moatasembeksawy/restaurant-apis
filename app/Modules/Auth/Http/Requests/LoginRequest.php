<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;
use App\Shared\Support\Phone\PhoneNormalizer;

class LoginRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (str_contains((string) $value, '@')) {
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                        $fail('validation.email')->translate();
                    }

                    return;
                }

                if (! PhoneNormalizer::isValid((string) $value)) {
                    $fail('validation.login_identifier')->translate();
                }
            }],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('login')) {
            $legacy = $this->input('email') ?? $this->input('phone');

            if ($legacy !== null) {
                $this->merge(['login' => $legacy]);
            }
        }

        if (is_string($this->input('login'))) {
            $this->merge(['login' => trim($this->input('login'))]);
        }
    }
}
