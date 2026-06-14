<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ETA;

use App\Modules\POS\Billing\Models\Payment;
use App\Modules\Tenant\Models\Tenant;

interface ETAAdapterInterface
{
    /**
     * Obtain a short-lived access token from ETA identity server.
     */
    public function getAccessToken(ETACredentials $credentials): string;

    /**
     * Submit a signed invoice document to the ETA portal.
     *
     * @param  array<string, mixed>  $invoiceDocument
     * @return array{uuid: string, longId: string, internalId: string, status: string}
     */
    public function submitInvoice(array $invoiceDocument, ETACredentials $credentials, string $accessToken): array;

    /**
     * Build the ETA-compliant invoice document from a payment record.
     *
     * @return array<string, mixed>
     */
    public function buildInvoiceDocument(
        Payment $payment,
        Tenant $tenant,
    ): array;
}
