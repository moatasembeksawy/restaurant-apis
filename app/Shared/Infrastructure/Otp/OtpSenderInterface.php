<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

interface OtpSenderInterface
{
    /** Channel name recorded with each issued code, e.g. "sms" or "whatsapp". */
    public function channel(): string;

    /**
     * Deliver a one-time code to an E.164 phone number.
     *
     * @throws OtpDeliveryException
     */
    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void;
}
