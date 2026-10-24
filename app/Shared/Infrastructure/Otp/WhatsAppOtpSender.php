<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API authentication template (body code + copy-code button):
 * https://developers.facebook.com/docs/whatsapp/business-management-api/authentication-templates
 */
class WhatsAppOtpSender implements OtpSenderInterface
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
        private readonly string $templateName,
        private readonly string $apiVersion = 'v19.0',
    ) {}

    public function channel(): string
    {
        return 'whatsapp';
    }

    public function send(string $phone, string $code, int $ttlMinutes, string $locale): void
    {
        if ($this->phoneNumberId === '' || $this->accessToken === '' || $this->templateName === '') {
            throw new OtpDeliveryException('WhatsApp OTP is not configured.');
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($phone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $this->templateName,
                        'language' => ['code' => $locale === 'ar' ? 'ar' : 'en'],
                        'components' => [
                            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                            [
                                'type' => 'button',
                                'sub_type' => 'url',
                                'index' => '0',
                                'parameters' => [['type' => 'text', 'text' => $code]],
                            ],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new OtpDeliveryException('WhatsApp is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new OtpDeliveryException('WhatsApp API error: '.(string) $response->json('error.message', $response->body()));
        }
    }
}
