<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\WhatsAppClient;

interface WhatsAppClientInterface
{
    /**
     * Send a pre-approved WhatsApp template message.
     *
     * @param  array<string, string>  $parameters  Template variable substitutions
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        string $languageCode,
        array $parameters = [],
    ): array;

    /**
     * Verify an inbound webhook signature.
     *
     * @throws \RuntimeException if signature is invalid
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
