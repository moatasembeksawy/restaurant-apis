<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMS Misr (Egypt) bulk SMS API: https://smsmisr.com/api-documentation
 */
class SmsMisrOtpSender implements OtpSenderInterface
{
    private const SUCCESS_CODE = '1901';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $sender,
        private readonly int $environment = 2,
        private readonly string $baseUrl = 'https://smsmisr.com/api',
    ) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void
    {
        if ($this->username === '' || $this->password === '' || $this->sender === '') {
            throw new OtpDeliveryException('SMS Misr is not configured.');
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post(rtrim($this->baseUrl, '/').'/SMS/', [
                    'environment' => $this->environment,
                    'username' => $this->username,
                    'password' => $this->password,
                    'sender' => $this->sender,
                    'mobile' => ltrim($phone, '+'),
                    'language' => $locale === 'ar' ? 2 : 1,
                    'message' => OtpMessage::text($code, $ttlMinutes, $locale),
                ]);
        } catch (ConnectionException $e) {
            throw new OtpDeliveryException('SMS Misr is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed() || (string) $response->json('code') !== self::SUCCESS_CODE) {
            throw new OtpDeliveryException('SMS Misr API error: '.$response->body());
        }
    }
}
