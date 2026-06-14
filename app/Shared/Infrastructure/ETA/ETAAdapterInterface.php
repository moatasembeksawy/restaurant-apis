<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ETA;

interface ETAAdapterInterface
{
    /**
     * Obtain a short-lived access token from ETA identity server.
     * Uses the tenant's digital certificate (PFX) for client credentials.
     */
    public function getAccessToken(string $clientId, string $clientSecret): string;

    /**
     * Submit a signed invoice document to the ETA portal.
     * Returns the ETA submission response with UUID and submission status.
     *
     * @param  array<string, mixed>  $invoiceDocument
     * @return array{uuid: string, longId: string, internalId: string, status: string}
     */
    public function submitInvoice(array $invoiceDocument, string $accessToken): array;

    /**
     * Build the ETA-compliant invoice document from a payment record.
     *
     * @return array<string, mixed>
     */
    public function buildInvoiceDocument(
        \App\Modules\POS\Billing\Models\Payment $payment,
        \App\Modules\Tenant\Models\Tenant $tenant,
    ): array;
}
