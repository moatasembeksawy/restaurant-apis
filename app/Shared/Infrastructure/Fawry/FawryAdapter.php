<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Fawry;

use RuntimeException;

class FawryAdapter
{
    public function __construct(
        private readonly string $merchantCode,
        private readonly string $securityKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * Build a Fawry charge request for subscription payment.
     *
     * @return array{charge_request: array<string, mixed>, signature: string, payment_url: string}
     */
    public function buildChargeRequest(
        string $merchantReference,
        int $amountCents,
        string $customerMobile,
        string $customerEmail,
        string $description,
    ): array {
        $amount = number_format($amountCents / 100, 2, '.', '');
        $paymentMethod = 'PAYATFAWRY';
        $customerProfileId = $merchantReference;

        $signature = hash('sha256', implode('', [
            $this->merchantCode,
            $merchantReference,
            $customerProfileId,
            $paymentMethod,
            $amount,
            $this->securityKey,
        ]));

        $chargeRequest = [
            'merchantCode' => $this->merchantCode,
            'merchantRefNum' => $merchantReference,
            'customerMobile' => $customerMobile,
            'customerEmail' => $customerEmail,
            'customerProfileId' => $customerProfileId,
            'amount' => $amount,
            'currencyCode' => 'EGP',
            'language' => 'ar-eg',
            'chargeItems' => [[
                'itemId' => $merchantReference,
                'description' => $description,
                'price' => $amount,
                'quantity' => 1,
            ]],
            'paymentMethod' => $paymentMethod,
            'signature' => $signature,
        ];

        return [
            'charge_request' => $chargeRequest,
            'signature' => $signature,
            'payment_url' => rtrim($this->baseUrl, '/').'/FawryPay.jsp',
        ];
    }

    /**
     * Verify Fawry server-to-server callback signature.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallbackSignature(array $payload): bool
    {
        $received = (string) ($payload['messageSignature'] ?? $payload['signature'] ?? '');

        if ($received === '') {
            return false;
        }

        $expected = hash('sha256', implode('', [
            (string) ($payload['fawryRefNumber'] ?? ''),
            (string) ($payload['merchantRefNumber'] ?? ''),
            number_format((float) ($payload['paymentAmount'] ?? 0), 2, '.', ''),
            (string) ($payload['orderAmount'] ?? ''),
            (string) ($payload['orderStatus'] ?? ''),
            (string) ($payload['paymentMethod'] ?? ''),
            $this->securityKey,
        ]));

        return hash_equals($expected, $received);
    }

    public function isSuccessful(array $payload): bool
    {
        return strtoupper((string) ($payload['orderStatus'] ?? '')) === 'PAID';
    }

    /**
     * @return array{tenant_id: int, plan: string, amount_cents: int, transaction_id: string}
     */
    public function extractPaymentMeta(array $payload): array
    {
        $reference = (string) ($payload['merchantRefNumber'] ?? '');

        $parts = explode('_', $reference);
        if (count($parts) < 3 || $parts[0] !== 'tenant') {
            throw new RuntimeException("Invalid Fawry merchant reference: {$reference}");
        }

        $amount = (float) ($payload['paymentAmount'] ?? $payload['orderAmount'] ?? 0);

        return [
            'tenant_id' => (int) $parts[1],
            'plan' => $parts[2],
            'amount_cents' => (int) round($amount * 100),
            'transaction_id' => (string) ($payload['fawryRefNumber'] ?? $reference),
        ];
    }
}
