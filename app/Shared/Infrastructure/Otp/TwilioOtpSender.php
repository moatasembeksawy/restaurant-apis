<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Twilio Programmable Messaging: https://www.twilio.com/docs/messaging/api/message-resource
 */
class TwilioOtpSender implements OtpSenderInterface
{
    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly ?string $from = null,
        private readonly ?string $messagingServiceSid = null,
    ) {}

    public function channel(): string
    {
        return 'sms';
    }

    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void
    {
        if ($this->accountSid === '' || $this->authToken === '' || (! $this->from && ! $this->messagingServiceSid)) {
            throw new OtpDeliveryException('Twilio is not configured.');
        }

        $sender = $this->messagingServiceSid
            ? ['MessagingServiceSid' => $this->messagingServiceSid]
            : ['From' => $this->from];

        try {
            $response = Http::asForm()
                ->withBasicAuth($this->accountSid, $this->authToken)
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                    'To' => $phone,
                    'Body' => OtpMessage::text($code, $ttlMinutes, $locale),
                    ...$sender,
                ]);
        } catch (ConnectionException $e) {
            throw new OtpDeliveryException('Twilio is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new OtpDeliveryException("Twilio API error ({$response->status()}): ".(string) $response->json('message'));
        }
    }
}
