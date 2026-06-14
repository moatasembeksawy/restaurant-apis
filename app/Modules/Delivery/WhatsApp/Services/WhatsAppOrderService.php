<?php

declare(strict_types=1);

namespace App\Modules\Delivery\WhatsApp\Services;

use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\WhatsAppClient\WhatsAppClientInterface;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class WhatsAppOrderService
{
    public function __construct(
        private readonly OrderPlacementService $orders,
        private readonly CustomerService $customers,
        private readonly WhatsAppClientInterface $whatsapp,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');

                $tenant = $this->resolveTenant($phoneNumberId);
                if (! $tenant) {
                    Log::warning('WhatsApp webhook: tenant not found', ['phone_number_id' => $phoneNumberId]);

                    continue;
                }

                app()->instance('tenant', $tenant);

                if (! $tenant->hasFeature('whatsapp_ordering')) {
                    Log::info('WhatsApp ordering not enabled for tenant', ['tenant_id' => $tenant->id]);

                    continue;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->processMessage($tenant, $message);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function processMessage(Tenant $tenant, array $message): ?Order
    {
        $from = (string) ($message['from'] ?? '');
        $text = (string) ($message['text']['body'] ?? '');

        if ($from === '' || $text === '') {
            return null;
        }

        $items = $this->parseOrderText($text);

        if ($items === null) {
            return null;
        }

        $branch = $tenant->defaultBranch ?? $tenant->branches()->where('is_default', true)->first();

        if (! $branch) {
            throw new InvalidArgumentException('Tenant has no default branch configured.');
        }

        $customer = $this->customers->findOrCreate(
            phone: $from,
            name: null,
        );

        $order = $this->orders->place(
            branchId: $branch->id,
            channel: 'whatsapp',
            items: $items,
            customerId: $customer->id,
            externalRef: (string) ($message['id'] ?? null),
        );

        $this->customers->recordOrder($customer, $order);

        Log::info('WhatsApp order created', [
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'from' => $from,
        ]);

        return $order;
    }

    /**
     * Parse order text format:
     *   ORDER
     *   12:2
     *   15:1
     *
     * @return array<int, array{menu_item_id: int, quantity: int}>|null
     */
    public function parseOrderText(string $text): ?array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", strtoupper($text)))));

        if ($lines === [] || $lines[0] !== 'ORDER') {
            return null;
        }

        $items = [];

        for ($i = 1; $i < count($lines); $i++) {
            if (! preg_match('/^(\d+):(\d+)$/', $lines[$i], $matches)) {
                return null;
            }

            $items[] = [
                'menu_item_id' => (int) $matches[1],
                'quantity' => (int) $matches[2],
            ];
        }

        return $items === [] ? null : $items;
    }

    private function resolveTenant(string $phoneNumberId): ?Tenant
    {
        if ($phoneNumberId === '') {
            return null;
        }

        return Tenant::query()
            ->where('whatsapp_phone_number_id', $phoneNumberId)
            ->first();
    }
}
