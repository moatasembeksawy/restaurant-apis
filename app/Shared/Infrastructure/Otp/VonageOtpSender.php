<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Vonage SMS API: https://developer.vonage.com/en/api/sms
 */
class VonageOtpSender implements OtpSenderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $from,
    ) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void
    {
        if ($this->apiKey === '' || $this->apiSecret === '' || $this->from === '') {
            throw new OtpDeliveryException('Vonage is not configured.');
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post('https://rest.nexmo.com/sms/json', [
                    'api_key' => $this->apiKey,
                    'api_secret' => $this->apiSecret,
                    'from' => $this->from,
                    'to' => ltrim($phone, '+'),
                    'text' => OtpMessage::text($code, $ttlMinutes, $locale),
                    'type' => 'unicode',
                ]);
        } catch (ConnectionException $e) {
            throw new OtpDeliveryException('Vonage is unreachable: '.$e->getMessage(), previous: $e);
        }

        // Vonage answers 200 even on failure; the per-message status is "0" on success.
        $status = (string) $response->json('messages.0.status', '');

        if ($response->failed() || $status !== '0') {
            throw new OtpDeliveryException('Vonage API error: '.(string) $response->json('messages.0.error-text', $response->body()));
        }
    }
}
