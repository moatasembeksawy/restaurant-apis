<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Aggregators\Services;

use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use InvalidArgumentException;

class AggregatorOrderService
{
    public function __construct(
        private readonly OrderPlacementService $orders,
        private readonly CustomerService $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{order: Order, created: bool}
     */
    public function ingest(Tenant $tenant, string $channel, array $payload): array
    {
        app()->instance('tenant', $tenant);

        $externalRef = (string) ($payload['external_order_id'] ?? $payload['external_ref'] ?? '');

        if ($externalRef === '') {
            throw new InvalidArgumentException('external_order_id is required.');
        }

        $existing = Order::query()
            ->where('channel', $channel)
            ->where('external_ref', $externalRef)
            ->first();

        if ($existing) {
            return ['order' => $existing->load('items'), 'created' => false];
        }

        $branchId = (int) ($payload['branch_id'] ?? $tenant->defaultBranch?->id ?? $tenant->branches()->value('id'));

        if (! $branchId) {
            throw new InvalidArgumentException('branch_id is required.');
        }

        $items = $payload['items'] ?? [];

        if ($items === []) {
            throw new InvalidArgumentException('items array is required.');
        }

        $customerId = null;

        if (! empty($payload['customer_phone'])) {
            $customer = $this->customers->findOrCreate(
                phone: (string) $payload['customer_phone'],
                name: $payload['customer_name'] ?? null,
                address: $payload['delivery_address'] ?? null,
            );
            $customerId = $customer->id;
        }

        $order = $this->orders->place(
            branchId: $branchId,
            channel: $channel,
            items: collect($items)->map(fn (array $item) => [
                'menu_item_id' => (int) $item['menu_item_id'],
                'quantity' => (int) ($item['quantity'] ?? 1),
                'notes' => $item['notes'] ?? null,
            ])->all(),
            customerId: $customerId,
            notes: $payload['notes'] ?? null,
            deliveryAddress: $payload['delivery_address'] ?? null,
            externalRef: $externalRef,
        );

        if (in_array($channel, ['talabat', 'elmenus', 'own_delivery'], true)) {
            $order->update(['delivery_status' => 'pending']);
        }

        AuditLogger::log('aggregator.order_received', $order, [
            'channel' => $channel,
            'external_ref' => $externalRef,
        ]);

        return ['order' => $order, 'created' => true];
    }

    public function verifySignature(Tenant $tenant, string $channel, string $payload, ?string $signature): bool
    {
        $secret = match ($channel) {
            'talabat' => $tenant->talabat_webhook_secret,
            'elmenus' => $tenant->elmenus_webhook_secret,
            default => null,
        };

        if (! $secret || ! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function resolveTenant(string $subdomain): ?Tenant
    {
        return Tenant::query()->where('subdomain', $subdomain)->first();
    }
}
