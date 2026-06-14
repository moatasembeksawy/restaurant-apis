<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\WhatsAppClient;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppClient implements WhatsAppClientInterface
{
    public function __construct(
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
        private readonly string $webhookSecret,
        private readonly string $apiVersion = 'v19.0',
    ) {}

    public function sendTemplate(
        string $to,
        string $templateName,
        string $languageCode,
        array $parameters = [],
    ): array {
        $to = $this->normalizePhone($to);

        $components = [];

        if (! empty($parameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    array_values($parameters),
                ),
            ];
        }

        $response = Http::withToken($this->accessToken)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $languageCode],
                    'components' => $components,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "WhatsApp API error: {$response->body()}",
            );
        }

        return $response->json();
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }

    private function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Egyptian numbers: 01x → 201x
        if (str_starts_with($cleaned, '01') && strlen($cleaned) === 11) {
            return '2'.$cleaned;
        }

        return $cleaned;
    }
}
