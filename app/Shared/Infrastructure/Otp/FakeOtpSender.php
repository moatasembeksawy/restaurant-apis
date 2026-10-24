<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

class FakeOtpSender implements OtpSenderInterface
{
    /** @var list<array{phone: string, code: string, ttl_minutes: int, locale: string}> */
    public array $sent = [];

    public bool $shouldFail = false;

    public function channel(): string
    {
        return 'fake';
    }

    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void
    {
        if ($this->shouldFail) {
            throw new OtpDeliveryException('Fake OTP delivery failure.');
        }

        $this->sent[] = ['phone' => $phone, 'code' => $code, 'ttl_minutes' => $ttlMinutes, 'locale' => $locale];
    }

    public function lastCodeFor(string $phone): ?string
    {
        $matches = array_values(array_filter($this->sent, fn (array $m): bool => $m['phone'] === $phone));

        return $matches === [] ? null : $matches[count($matches) - 1]['code'];
    }
}
