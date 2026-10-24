<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

final class OtpMessage
{
    public static function text(string $code, int $ttlMinutes, string $locale): string
    {
        return __('Your :app verification code is :code. It expires in :minutes minutes.', [
            'app' => config('app.name'),
            'code' => $code,
            'minutes' => $ttlMinutes,
        ], $locale);
    }
}
