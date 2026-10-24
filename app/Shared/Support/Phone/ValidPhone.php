<?php

declare(strict_types=1);

namespace App\Shared\Support\Phone;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidPhone implements ValidationRule
{
    /**
     * @param  bool  $mobileOnly  Reject landlines (needed for numbers that receive OTPs or are used to log in).
     */
    public function __construct(private readonly bool $mobileOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNormalizer::isValid($value, $this->mobileOnly)) {
            $fail($this->mobileOnly ? 'validation.mobile_phone' : 'validation.phone')->translate();
        }
    }
}
