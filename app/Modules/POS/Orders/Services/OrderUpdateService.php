<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Services;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Support\OrderFulfillment;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrderUpdateService
{
    /**
     * @param  array{
     *     channel?: string,
     *     fulfillment_type?: string,
     *     floor_table_id?: int|null,
     *     notes?: string|null,
     *     delivery_address?: string|null,
     *     delivery_fee?: float|int|string|null,
     *     customer_id?: int|null
     * }  $data
     */
    public function update(Order $order, array $data): Order
    {
        if (! $order->canEdit()) {
            throw new InvalidArgumentException('Cannot update an order with status: '.$order->status);
        }

        return DB::transaction(function () use ($order, $data): Order {
            $channel = $data['channel'] ?? $order->channel;
            $deliveryAddress = array_key_exists('delivery_address', $data)
                ? $data['delivery_address']
                : $order->delivery_address;
            $floorTableId = array_key_exists('floor_table_id', $data)
                ? ($data['floor_table_id'] !== null ? (int) $data['floor_table_id'] : null)
                : ($order->floor_table_id !== null ? (int) $order->floor_table_id : null);

            $fulfillmentType = $this->resolveFulfillmentType($order, $data, $channel, $floorTableId);

            if (
                in_array($fulfillmentType, [OrderFulfillment::TAKEAWAY, OrderFulfillment::DELIVERY], true)
                && ! array_key_exists('floor_table_id', $data)
            ) {
                $floorTableId = null;
            }

            $deliveryFee = array_key_exists('delivery_fee', $data)
                ? (float) $data['delivery_fee']
                : ($fulfillmentType === OrderFulfillment::DELIVERY ? (float) $order->delivery_fee : 0);

            $deliveryFee = OrderFulfillment::normalizeDeliveryFee($fulfillmentType, $deliveryFee);

            OrderFulfillment::validate(
                fulfillmentType: $fulfillmentType,
                channel: $channel,
                floorTableId: $floorTableId,
                deliveryAddress: $deliveryAddress,
            );

            /** @var Tenant $tenant */
            $tenant = app('tenant');

            if (
                $fulfillmentType === OrderFulfillment::DELIVERY
                && $channel === 'qr'
                && ! $tenant->hasFeature('delivery')
            ) {
                throw new InvalidArgumentException('Delivery is not enabled for this restaurant.');
            }

            if (array_key_exists('customer_id', $data) && $data['customer_id'] !== null) {
                if (Customer::query()->whereKey((int) $data['customer_id'])->doesntExist()) {
                    throw new InvalidArgumentException('Customer not found.');
                }
            }

            if ($floorTableId !== null) {
                $table = FloorTable::query()
                    ->whereKey($floorTableId)
                    ->where('branch_id', $order->branch_id)
                    ->first();

                if ($table === null) {
                    throw new InvalidArgumentException('Table not found.');
                }
            }

            $previousTableId = $order->floor_table_id !== null ? (int) $order->floor_table_id : null;

            $attributes = [
                'channel' => $channel,
                'fulfillment_type' => $fulfillmentType,
                'floor_table_id' => $floorTableId,
                'delivery_address' => $deliveryAddress,
                'delivery_fee' => $deliveryFee,
            ];

            if (array_key_exists('notes', $data)) {
                $attributes['notes'] = $data['notes'];
            }

            if (array_key_exists('customer_id', $data)) {
                $attributes['customer_id'] = $data['customer_id'];
            }

            $requiresTracking = OrderFulfillment::requiresDeliveryTracking($fulfillmentType);
            if ($requiresTracking && $order->delivery_status === null) {
                $attributes['delivery_status'] = 'pending';
            }

            if (! $requiresTracking && $order->delivery_status !== null) {
                $attributes['delivery_status'] = null;
                $attributes['rider_id'] = null;
            }

            $order->update($attributes);
            $order->recalculateTotals();

            $this->syncTableOccupancy($previousTableId, $floorTableId);

            AuditLogger::log('order.updated', $order, [
                'channel' => $order->channel,
                'fulfillment_type' => $order->fulfillment_type,
                'floor_table_id' => $order->floor_table_id,
            ]);

            return $order->fresh(['items', 'table', 'waiter', 'customer']) ?? $order;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveFulfillmentType(Order $order, array $data, string $channel, ?int $floorTableId): string
    {
        if (array_key_exists('fulfillment_type', $data)) {
            return $data['fulfillment_type'];
        }

        if (array_key_exists('channel', $data) && in_array($channel, ['talabat', 'elmenus', 'own_delivery'], true)) {
            return OrderFulfillment::DELIVERY;
        }

        if ($floorTableId !== null && array_key_exists('floor_table_id', $data)) {
            return OrderFulfillment::DINE_IN;
        }

        return $order->fulfillment_type;
    }

    private function syncTableOccupancy(?int $previousTableId, ?int $newTableId): void
    {
        if ($previousTableId === $newTableId) {
            return;
        }

        if ($previousTableId !== null) {
            FloorTable::find($previousTableId)?->update(['status' => 'free']);
        }

        if ($newTableId !== null) {
            FloorTable::find($newTableId)?->update(['status' => 'occupied']);
        }
    }
}
