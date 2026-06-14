<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Paymob;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymobAdapter
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $hmacSecret,
        private readonly string $baseUrl,
        private readonly ?int $integrationId = null,
        private readonly ?int $iframeId = null,
        private readonly string $currency = 'EGP',
    ) {}

    /**
     * Create a Paymob Accept checkout session for subscription billing.
     *
     * @param  array<string, mixed>  $billingData
     * @return array{checkout_url: string, payment_token: string, paymob_order_id: int, merchant_reference: string}
     */
    public function createCheckoutSession(
        int $amountCents,
        string $merchantOrderId,
        array $billingData,
    ): array {
        if (empty($this->apiKey) || empty($this->integrationId) || empty($this->iframeId)) {
            throw new RuntimeException('Paymob is not configured. Set PAYMOB_API_KEY, PAYMOB_INTEGRATION_ID, and PAYMOB_IFRAME_ID.');
        }

        $authToken = $this->authenticate();

        $orderResponse = Http::timeout(15)
            ->post("{$this->baseUrl}/ecommerce/orders", [
                'auth_token' => $authToken,
                'delivery_needed' => false,
                'amount_cents' => $amountCents,
                'currency' => $this->currency,
                'merchant_order_id' => $merchantOrderId,
                'items' => [],
            ]);

        if ($orderResponse->failed()) {
            throw new RuntimeException('Paymob order creation failed: '.$orderResponse->body());
        }

        $paymobOrderId = (int) $orderResponse->json('id');

        $keyResponse = Http::timeout(15)
            ->post("{$this->baseUrl}/acceptance/payment_keys", [
                'auth_token' => $authToken,
                'amount_cents' => $amountCents,
                'expiration' => 3600,
                'order_id' => $paymobOrderId,
                'billing_data' => array_merge([
                    'apartment' => 'NA',
                    'email' => 'billing@restoapp.eg',
                    'floor' => 'NA',
                    'first_name' => 'Restaurant',
                    'street' => 'NA',
                    'building' => 'NA',
                    'phone_number' => '01000000000',
                    'shipping_method' => 'NA',
                    'postal_code' => 'NA',
                    'city' => 'Cairo',
                    'country' => 'EG',
                    'last_name' => 'Owner',
                    'state' => 'Cairo',
                ], $billingData),
                'currency' => $this->currency,
                'integration_id' => $this->integrationId,
            ]);

        if ($keyResponse->failed()) {
            throw new RuntimeException('Paymob payment key creation failed: '.$keyResponse->body());
        }

        $paymentToken = (string) $keyResponse->json('token');
        $checkoutUrl = rtrim($this->baseUrl, '/')."/acceptance/iframes/{$this->iframeId}?payment_token={$paymentToken}";

        return [
            'checkout_url' => $checkoutUrl,
            'payment_token' => $paymentToken,
            'paymob_order_id' => $paymobOrderId,
            'merchant_reference' => $merchantOrderId,
        ];
    }

    /**
     * Verify Paymob HMAC signature on inbound webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public function verifyHmac(array $data, string $receivedHmac): bool
    {
        if ($this->hmacSecret === '') {
            return false;
        }

        $obj = $data['obj'] ?? [];

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
     * @param  array<string, mixed>  $data
     */
    public function isSuccessful(array $data): bool
    {
        return (bool) ($data['obj']['success'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{tenant_id: int, plan: string, amount_cents: int, transaction_id: string}
     */
    public function extractPaymentMeta(array $data): array
    {
        $merchant = (string) ($data['obj']['order']['merchant_order_id'] ?? '');

        $parts = explode('_', $merchant);

        if (count($parts) < 3 || $parts[0] !== 'tenant') {
            throw new RuntimeException("Invalid Paymob merchant order ID: {$merchant}");
        }

        return [
            'tenant_id' => (int) $parts[1],
            'plan' => $parts[2],
            'amount_cents' => (int) ($data['obj']['amount_cents'] ?? 0),
            'transaction_id' => (string) ($data['obj']['id'] ?? $merchant),
        ];
    }

    private function authenticate(): string
    {
        $response = Http::timeout(15)
            ->post("{$this->baseUrl}/auth/tokens", [
                'api_key' => $this->apiKey,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Paymob authentication failed: '.$response->body());
        }

        return (string) $response->json('token');
    }
}
