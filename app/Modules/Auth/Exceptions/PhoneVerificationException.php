<?php

declare(strict_types=1);

namespace App\Modules\Auth\Exceptions;

use RuntimeException;

class PhoneVerificationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('Phone verification is not available yet.', 'PHONE_VERIFICATION_DISABLED', 503);
    }

    public static function missingPhone(): self
    {
        return new self('Add a phone number before verifying it.', 'PHONE_MISSING', 422);
    }

    public static function cooldown(int $seconds): self
    {
        return new self('Please wait before requesting another code.', 'PHONE_CODE_COOLDOWN', 429, $seconds);
    }

    public static function invalidCode(): self
    {
        return new self('Invalid or expired verification code.', 'PHONE_CODE_INVALID', 422);
    }

    public static function tooManyAttempts(): self
    {
        return new self('Too many incorrect codes. Request a new one.', 'PHONE_CODE_LOCKED', 429);
    }

    public static function deliveryFailed(): self
    {
        return new self('We could not send the verification code. Please try again later.', 'PHONE_CODE_DELIVERY_FAILED', 502);
    }
}
