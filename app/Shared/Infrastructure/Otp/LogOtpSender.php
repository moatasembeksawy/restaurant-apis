<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the code to the log instead of sending it.
 */
class LogOtpSender implements OtpSenderInterface
{
    public function channel(): string
    {
        return 'log';
    }

    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void
    {
        Log::info('Phone verification code issued.', [
            'phone' => $phone,
            'code' => app()->isProduction() ? '[redacted]' : $code,
            'ttl_minutes' => $ttlMinutes,
        ]);
    }
}
