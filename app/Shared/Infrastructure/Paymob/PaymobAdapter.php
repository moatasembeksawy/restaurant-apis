<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Paymob;

use RuntimeException;

class PaymobAdapter
{
    public function __construct(
        private readonly string $hmacSecret,
    ) {}

    /**
     * Verify Paymob HMAC signature on inbound webhook.
     * Paymob concatenates specific fields in a defined order and signs with HMAC-SHA512.
     *
     * @param  array<string, mixed>  $data  Parsed webhook payload
     */
    public function verifyHmac(array $data, string $receivedHmac): bool
    {
        $obj = $data['obj'] ?? [];

        // Paymob HMAC fields in required concatenation order
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured',
            'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
            'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
            'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
            'source_data.sub_type', 'source_data.type', 'success',
        ];

        $concatenated = '';
        foreach ($fields as $field) {
            $keys = explode('.', $field);
            $value = $obj;
            foreach ($keys as $key) {
                $value = $value[$key] ?? '';
            }
            $concatenated .= (string) $value;
        }

        $expected = hash_hmac('sha512', $concatenated, $this->hmacSecret);

        return hash_equals($expected, $receivedHmac);
    }

    /**
     * Extract whether the payment was successful from webhook payload.
     */
    public function isSuccessful(array $data): bool
    {
        return (bool) ($data['obj']['success'] ?? false);
    }

    /**
     * Extract order metadata from webhook payload.
     *
     * @return array{tenant_id: int, plan: string, amount: int}
     */
    public function extractOrderMeta(array $data): array
    {
        $merchant = $data['obj']['order']['merchant_order_id'] ?? '';

        // Format: tenant_{id}_{plan} e.g. "tenant_5_pro"
        $parts = explode('_', $merchant);

        if (count($parts) < 3 || $parts[0] !== 'tenant') {
            throw new RuntimeException("Invalid Paymob merchant order ID: {$merchant}");
        }

        return [
            'tenant_id' => (int) $parts[1],
            'plan' => $parts[2],
            'amount' => (int) ($data['obj']['amount_cents'] ?? 0),
        ];
    }
}
