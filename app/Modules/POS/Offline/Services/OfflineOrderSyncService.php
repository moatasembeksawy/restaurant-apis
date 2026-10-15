<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Services;

use App\Models\User;
use App\Modules\Delivery\WhatsApp\Jobs\SendWhatsAppNotificationJob;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Services\PaymentSettlementService;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offline\Models\OfflineSyncOperation;
use App\Modules\POS\Offline\Support\OfflineOperations;
use App\Modules\POS\Orders\Events\OrderItemAdded;
use App\Modules\POS\Orders\Events\OrderItemReady;
use App\Modules\POS\Orders\Events\OrderReady;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Orders\Services\OrderLineService;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Broadcasting\SafeBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OfflineOrderSyncService
{
    public function __construct(
        private readonly OrderPlacementService $orders,
        private readonly OrderLineService $lines,
        private readonly PaymentSettlementService $payments,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array<int, array<string, mixed>>
     */
    public function sync(
        Tenant $tenant,
        User $user,
        int $branchId,
        string $deviceId,
        array $operations,
    ): array {
        return collect($operations)
            ->map(fn (array $operation): array => $this->process(
                tenant: $tenant,
                user: $user,
                branchId: $branchId,
                deviceId: $deviceId,
                operation: $operation,
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function process(
        Tenant $tenant,
        User $user,
        int $branchId,
        string $deviceId,
        array $operation,
    ): array {
        $operationId = (string) $operation['operation_id'];

        $processed = OfflineSyncOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if ($processed instanceof OfflineSyncOperation) {
            return array_merge($processed->resultPayload(), ['disposition' => 'duplicate']);
        }

        try {
            return DB::transaction(function () use (
                $tenant,
                $user,
                $branchId,
                $deviceId,
                $operation,
                $operationId,
            ): array {
                /** @var array<string, mixed> $payload */
                $payload = $operation['payload'];
                $applied = $this->apply($user, $branchId, $deviceId, (string) $operation['type'], $payload, (string) $operation['occurred_at']);
                $result = array_merge([
                    'operation_id' => $operationId,
                    'type' => (string) $operation['type'],
                    'disposition' => 'applied',
                ], $applied);

                OfflineSyncOperation::create([
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'operation_id' => $operationId,
                    'device_id' => $deviceId,
                    'type' => (string) $operation['type'],
                    'client_order_id' => $payload['client_order_id'] ?? null,
                    'result' => $result,
                    'occurred_at' => CarbonImmutable::parse((string) $operation['occurred_at']),
                    'processed_at' => now(),
                ]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            $processed = OfflineSyncOperation::query()
                ->where('operation_id', $operationId)
                ->first();

            if ($processed instanceof OfflineSyncOperation) {
                return array_merge($processed->resultPayload(), ['disposition' => 'duplicate']);
            }

            return $this->process($tenant, $user, $branchId, $deviceId, $operation);
        } catch (InvalidArgumentException $exception) {
            $code = str_contains($exception->getMessage(), 'permission')
                ? 'FORBIDDEN'
                : 'ORDER_VALIDATION_FAILED';

            return [
                'operation_id' => $operationId,
                'type' => (string) $operation['type'],
                'disposition' => 'rejected',
                'error' => [
                    'code' => $code,
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function apply(
        User $user,
        int $branchId,
        string $deviceId,
        string $type,
        array $payload,
        string $occurredAt,
    ): array {
        return match ($type) {
            OfflineOperations::ORDER_CREATED => $this->createOrder($user, $branchId, $deviceId, $payload, $occurredAt),
            OfflineOperations::ITEM_ADDED => $this->addItem($payload),
            OfflineOperations::ITEM_UPDATED => $this->updateItem($payload),
            OfflineOperations::ITEM_REMOVED => $this->removeItem($payload),
            OfflineOperations::STATUS_CHANGED => $this->changeStatus($payload),
            OfflineOperations::ITEM_READY => $this->markItemReady($payload),
            OfflineOperations::PAYMENT_RECORDED => $this->recordPayment($user, $payload),
            default => throw new InvalidArgumentException("Unsupported operation type: {$type}."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createOrder(User $user, int $branchId, string $deviceId, array $payload, string $occurredAt): array
    {
        $clientOrderId = (string) $payload['client_order_id'];
        $order = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->first();

        if ($order instanceof Order) {
            return array_merge($this->orderResult($order, $clientOrderId), ['disposition' => 'duplicate']);
        }

        $order = $this->orders->place(
            branchId: $branchId,
            channel: (string) $payload['channel'],
            items: $payload['items'],
            floorTableId: isset($payload['floor_table_id']) ? (int) $payload['floor_table_id'] : null,
            waiterId: $user->id,
            customerId: isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            notes: $payload['notes'] ?? null,
            deliveryAddress: $payload['delivery_address'] ?? null,
            fulfillmentType: $payload['fulfillment_type'] ?? null,
            deliveryFee: isset($payload['delivery_fee']) ? (float) $payload['delivery_fee'] : null,
            deliveryFeeProvided: array_key_exists('delivery_fee', $payload),
            customerAddressId: isset($payload['customer_address_id']) ? (int) $payload['customer_address_id'] : null,
            districtId: isset($payload['district_id']) ? (int) $payload['district_id'] : null,
            couponCode: $payload['coupon_code'] ?? null,
            clientOrderId: $clientOrderId,
            sourceDeviceId: $deviceId,
            offlineCreatedAt: CarbonImmutable::parse($occurredAt),
        );

        return array_merge($this->orderResult($order, $clientOrderId), [
            'warnings' => $this->priceWarnings($payload['items']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addItem(array $payload): array
    {
        $order = $this->resolveOrder($payload);

        if (! $order->canAddItems()) {
            throw new InvalidArgumentException('Cannot add items to an order with status: '.$order->status);
        }

        $clientItemId = (string) $payload['client_item_id'];
        $existing = $order->items()->where('client_item_id', $clientItemId)->first();

        if ($existing instanceof OrderItem) {
            return array_merge($this->orderResult($order, (string) $payload['client_order_id']), [
                'disposition' => 'duplicate',
                'client_item_id' => $clientItemId,
                'server_item_id' => $existing->id,
            ]);
        }

        $item = $this->lines->add($order, $payload);
        $order->recalculateTotals();

        if ($order->status === 'ready') {
            $order->update(['status' => 'cooking']);
        }

        SafeBroadcast::toOthers(new OrderItemAdded($item->fresh('order.table')));

        return array_merge($this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']), [
            'client_item_id' => $clientItemId,
            'server_item_id' => $item->id,
            'warnings' => $this->priceWarnings([$payload]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function updateItem(array $payload): array
    {
        $order = $this->resolveOrder($payload);

        if (! $order->canAddItems()) {
            throw new InvalidArgumentException('Cannot update items on an order with status: '.$order->status);
        }

        $item = $this->resolveItem($order, $payload);

        if (! in_array($item->status, ['pending', 'ready'], true)) {
            throw new InvalidArgumentException('Cannot update an item that is already '.$item->status.'.');
        }

        $previousQuantity = (int) $item->quantity;
        $item = $this->lines->update($order, $item, $payload);
        $order->recalculateTotals();

        if ((int) $item->quantity > $previousQuantity && in_array($order->status, ['ready', 'cooking'], true)) {
            $order->update(['status' => 'cooking']);
        }

        return array_merge($this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']), [
            'client_item_id' => (string) $payload['client_item_id'],
            'server_item_id' => $item->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function removeItem(array $payload): array
    {
        $order = $this->resolveOrder($payload);

        if (! $order->canAddItems()) {
            throw new InvalidArgumentException('Cannot remove items from an order with status: '.$order->status);
        }

        $item = $order->items()
            ->where('client_item_id', (string) $payload['client_item_id'])
            ->first();

        if (! $item instanceof OrderItem) {
            return array_merge($this->orderResult($order, (string) $payload['client_order_id']), [
                'disposition' => 'duplicate',
                'client_item_id' => (string) $payload['client_item_id'],
            ]);
        }

        if ($item->status !== 'pending' && ! $item->isPackageParent()) {
            throw new InvalidArgumentException('Cannot remove an item that is already '.$item->status.'.');
        }

        if ($item->isPackageParent() && $item->children()->where('status', '!=', 'pending')->exists()) {
            throw new InvalidArgumentException('Cannot remove a package after kitchen has started it.');
        }

        $this->lines->remove($order, $item);
        $order->recalculateTotals();

        return $this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function changeStatus(array $payload): array
    {
        $order = $this->resolveOrder($payload);
        $status = (string) $payload['status'];

        if ($order->status === $status) {
            return array_merge($this->orderResult($order, (string) $payload['client_order_id']), [
                'disposition' => 'duplicate',
            ]);
        }

        if (! $order->canTransitionTo($status)) {
            throw new InvalidArgumentException("Cannot transition order from '{$order->status}' to '{$status}'.");
        }

        $order->update(['status' => $status]);

        if (in_array($status, ['completed', 'cancelled'], true) && $order->floor_table_id) {
            FloorTable::find($order->floor_table_id)?->update(['status' => 'free']);
        }

        if ($status === 'cancelled') {
            AuditLogger::log('order.cancelled', $order);
        }

        return $this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function markItemReady(array $payload): array
    {
        $order = $this->resolveOrder($payload);
        $item = $this->resolveItem($order, $payload);

        if ($item->status === 'ready') {
            return array_merge($this->orderResult($order, (string) $payload['client_order_id']), [
                'disposition' => 'duplicate',
                'client_item_id' => (string) $payload['client_item_id'],
                'server_item_id' => $item->id,
            ]);
        }

        $item->update([
            'status' => 'ready',
            'cooked_at' => now(),
        ]);

        $order = $item->order()->with('table')->first() ?? $order;
        $allReady = $order->items()
            ->where('line_type', '!=', OrderItem::LINE_PACKAGE)
            ->whereNotIn('status', ['ready', 'served', 'cancelled'])
            ->doesntExist();

        if ($allReady) {
            $order->update(['status' => 'ready']);
            $order = $order->fresh(['table', 'customer', 'tenant']) ?? $order;
            SafeBroadcast::toOthers(new OrderReady($order));

            if ($order->customer_id && $order->tenant?->whatsapp_phone_number_id && $order->tenant->hasFeature('whatsapp_ordering')) {
                SendWhatsAppNotificationJob::dispatch($order, 'order_ready');
            }
        } else {
            $order->update(['status' => 'cooking']);
        }

        SafeBroadcast::toOthers(new OrderItemReady($item->fresh() ?? $item));

        AuditLogger::log('kitchen.item_ready', $item, [
            'order_id' => $order->id,
            'item_name_ar' => $item->item_name_ar,
        ]);

        return array_merge($this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']), [
            'client_item_id' => (string) $payload['client_item_id'],
            'server_item_id' => $item->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function recordPayment(User $user, array $payload): array
    {
        if (! $user->checkPermissionTo('payments.process', 'web')) {
            throw new InvalidArgumentException('You do not have permission to record payments.');
        }

        if (($payload['method'] ?? null) !== 'cash') {
            throw new InvalidArgumentException('Only cash payments can be recorded offline.');
        }

        $order = $this->resolveOrder($payload);
        $clientPaymentId = (string) $payload['client_payment_id'];
        $existing = Payment::query()->where('client_payment_id', $clientPaymentId)->first();

        if ($existing instanceof Payment) {
            return array_merge($this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']), [
                'disposition' => 'duplicate',
                'client_payment_id' => $clientPaymentId,
                'server_payment_id' => $existing->id,
            ]);
        }

        $result = $this->payments->settle($order, [
            'method' => 'cash',
            'amount' => $payload['amount'],
            'cash_tendered' => $payload['cash_tendered'] ?? null,
            'client_payment_id' => $clientPaymentId,
        ], $user);

        return array_merge($this->orderResult($order->fresh() ?? $order, (string) $payload['client_order_id']), [
            'client_payment_id' => $clientPaymentId,
            'server_payment_id' => $result['payment']->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrder(array $payload): Order
    {
        $query = Order::query();

        if (! empty($payload['client_order_id'])) {
            $query->where('client_order_id', $payload['client_order_id']);
        } elseif (! empty($payload['server_order_id'])) {
            $query->whereKey((int) $payload['server_order_id']);
        } else {
            throw new InvalidArgumentException('Order not found.');
        }

        $order = $query->first();

        if (! $order instanceof Order) {
            throw new InvalidArgumentException('Order not found.');
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveItem(Order $order, array $payload): OrderItem
    {
        $item = $order->items()
            ->where('client_item_id', (string) $payload['client_item_id'])
            ->first();

        if (! $item instanceof OrderItem) {
            throw new InvalidArgumentException('Order item not found.');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderResult(Order $order, string $clientOrderId): array
    {
        $order->refresh();

        return [
            'client_order_id' => $clientOrderId,
            'server_order_id' => $order->id,
            'server_status' => $order->status,
            'total' => (float) $order->total,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function priceWarnings(array $items): array
    {
        $warnings = [];

        foreach ($items as $item) {
            if (! isset($item['menu_item_id'], $item['unit_price'])) {
                continue;
            }

            $menuItem = MenuItem::query()->find((int) $item['menu_item_id']);

            if (! $menuItem instanceof MenuItem) {
                continue;
            }

            if (round((float) $item['unit_price'], 2) !== round((float) $menuItem->price, 2)) {
                $warnings[] = [
                    'code' => 'PRICE_CHANGED',
                    'menu_item_id' => $menuItem->id,
                    'sold_price' => round((float) $item['unit_price'], 2),
                    'current_price' => round((float) $menuItem->price, 2),
                ];
            }
        }

        return $warnings;
    }
}
