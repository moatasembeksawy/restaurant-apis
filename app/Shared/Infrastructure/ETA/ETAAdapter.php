<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ETA;

use App\Modules\POS\Billing\Models\Payment;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ETAAdapter implements ETAAdapterInterface
{
    public function __construct(
        private readonly string $portalUrl,
        private readonly string $tokenUrl,
    ) {}

    public function getAccessToken(ETACredentials $credentials): string
    {
        $response = $this->httpClient($credentials)
            ->asForm()
            ->post($this->tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $credentials->clientId,
                'client_secret' => $credentials->clientSecret,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('ETA token request failed: '.$response->body());
        }

        return $response->json('access_token');
    }

    public function submitInvoice(array $invoiceDocument, ETACredentials $credentials, string $accessToken): array
    {
        $response = $this->httpClient($credentials)
            ->withToken($accessToken)
            ->timeout(30)
            ->post("{$this->portalUrl}/api/v1/documentsubmissions", [
                'documents' => [$invoiceDocument],
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        $body = $response->json();

        if (! empty($body['rejectedDocuments'])) {
            $reason = $body['rejectedDocuments'][0]['error']['details'][0]['message'] ?? 'Unknown rejection reason';
            throw new RuntimeException("ETA rejected invoice: {$reason}");
        }

        return $body['acceptedDocuments'][0] ?? $body;
    }

    public function buildInvoiceDocument(Payment $payment, Tenant $tenant): array
    {
        $order = $payment->order->load('items');
        $issuedAt = $payment->created_at->format('Y-m-d\TH:i:s\Z');

        $invoiceLines = $order->items->map(function ($item, $index) {
            $vatAmount = round($item->unit_price * $item->quantity * 0.14, 5);

            return [
                'description' => $item->item_name_ar,
                'itemType' => 'GS1',
                'itemCode' => 'EG-'.$item->menu_item_id,
                'unitType' => 'EA',
                'quantity' => $item->quantity,
                'internalCode' => (string) ($index + 1),
                'salesTotal' => (float) $item->subtotal,
                'total' => round((float) $item->subtotal * 1.14, 5),
                'valueDifference' => 0,
                'totalTaxableFees' => 0,
                'netTotal' => (float) $item->subtotal,
                'itemsDiscount' => 0,
                'unitValue' => [
                    'currencySold' => 'EGP',
                    'amountEGP' => (float) $item->unit_price,
                ],
                'taxableItems' => [[
                    'taxType' => 'T1',
                    'amount' => $vatAmount,
                    'subType' => 'V001',
                    'rate' => 14,
                ]],
            ];
        })->values()->all();

        $netAmount = (float) $payment->amount;
        $vatTotal = round($netAmount * 0.14, 5);
        $totalAmount = round($netAmount * 1.14, 5);

        return [
            'issuer' => [
                'type' => 'B',
                'id' => $tenant->eta_taxpayer_id ?: config('services.eta.client_id'),
                'name' => $tenant->name,
                'address' => [
                    'branchID' => $tenant->eta_branch_id ?? '0',
                    'country' => 'EG',
                    'governate' => 'Cairo',
                    'regionCity' => 'Cairo',
                    'street' => 'Main Street',
                    'buildingNumber' => '1',
                ],
            ],
            'receiver' => [
                'type' => 'P',
                'id' => '0000000000',
                'name' => 'Cash Customer',
                'address' => [
                    'country' => 'EG',
                    'governate' => 'Cairo',
                    'regionCity' => 'Cairo',
                    'street' => '-',
                    'buildingNumber' => '1',
                ],
            ],
            'documentType' => 'I',
            'documentTypeVersion' => '1.0',
            'dateTimeIssued' => $issuedAt,
            'taxpayerActivityCode' => '5610',
            'internalID' => (string) $payment->id,
            'invoiceLines' => $invoiceLines,
            'taxTotals' => [[
                'taxType' => 'T1',
                'amount' => $vatTotal,
            ]],
            'netAmount' => $netAmount,
            'taxAmount' => $vatTotal,
            'totalAmount' => $totalAmount,
            'totalSales' => $netAmount,
            'totalDiscount' => 0,
            'extraDiscountAmount' => 0,
            'totalItemsDiscountAmount' => 0,
        ];
    }

    private function httpClient(ETACredentials $credentials): PendingRequest
    {
        $client = Http::timeout(15);
        $options = $this->tlsOptions($credentials);

        if ($options !== []) {
            $client = $client->withOptions($options);
        }

        return $client;
    }

    /** @return array<string, mixed> */
    private function tlsOptions(ETACredentials $credentials): array
    {
        if (! $credentials->usesMutualTls()) {
            return [];
        }

        if (! Storage::disk('local')->exists($credentials->certPath)) {
            return [];
        }

        $path = Storage::disk('local')->path($credentials->certPath);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['p12', 'pfx'], true)) {
            return ['cert' => [$path, '']];
        }

        return ['cert' => $path];
    }
}
