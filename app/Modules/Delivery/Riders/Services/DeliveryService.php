<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Services;

use App\Models\User;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Authorization\BranchAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeliveryService
{
    /** @var array<int, string> */
    private const DELIVERY_TRANSITIONS = [
        'pending' => ['assigned', 'cancelled'],
        'assigned' => ['picked_up', 'cancelled'],
        'picked_up' => ['en_route', 'cancelled'],
        'en_route' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function assignRider(Order $order, User $rider): Order
    {
        if (! $order->isDelivery()) {
            throw new InvalidArgumentException('Only delivery orders can be assigned to a rider.');
        }

        if ($rider->role !== 'rider' || $rider->tenant_id !== $order->tenant_id) {
            throw new InvalidArgumentException('Invalid rider for this order.');
        }

        $order->update([
            'rider_id' => $rider->id,
            'delivery_status' => 'assigned',
        ]);

        AuditLogger::log('delivery.rider_assigned', $order, [
            'rider_id' => $rider->id,
            'rider_name' => $rider->name,
        ]);

        return $order->fresh(['rider', 'payment.splits']);
    }

    /**
     * @param  list<int>  $orderIds
     * @return Collection<int, Order>
     */
    public function bulkAssignRider(array $orderIds, User $rider, User $assignedBy): Collection
    {
        if (
            $rider->role !== 'rider'
            || ! $rider->is_active
            || $rider->tenant_id !== $assignedBy->tenant_id
        ) {
            throw new InvalidArgumentException('Invalid rider.');
        }

        return DB::transaction(function () use ($orderIds, $rider, $assignedBy): Collection {
            $orders = Order::query()
                ->whereKey($orderIds)
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($orderIds)) {
                throw new InvalidArgumentException('One or more orders could not be found.');
            }

            foreach ($orders as $order) {
                if (! BranchAccess::canAccess($assignedBy, (int) $order->branch_id)) {
                    throw new InvalidArgumentException('One or more orders are outside your branch access.');
                }

                if (
                    ! $order->isDelivery()
                    || $order->rider_id !== null
                    || $order->delivery_status !== 'pending'
                    || in_array($order->status, ['cancelled', 'completed', 'paid', 'refunded'], true)
                ) {
                    throw new InvalidArgumentException(__('Order :order_id is not available for assignment.', ['order_id' => $order->id]));
                }

                if ((int) $rider->branch_id !== (int) $order->branch_id) {
                    throw new InvalidArgumentException(__('Rider and order :order_id must belong to the same branch.', [
                        'order_id' => $order->id,
                    ]));
                }
            }

            foreach ($orders as $order) {
                $order->update([
                    'rider_id' => $rider->id,
                    'delivery_status' => 'assigned',
                ]);

                AuditLogger::log('delivery.rider_assigned', $order, [
                    'rider_id' => $rider->id,
                    'rider_name' => $rider->name,
                    'bulk' => true,
                ]);
            }

            return Order::query()
                ->whereKey($orderIds)
                ->with(['items', 'customer', 'district', 'customerAddress', 'rider', 'payment.splits'])
                ->get();
        });
    }

    public function updateDeliveryStatus(Order $order, string $status): Order
    {
        if ($order->delivery_status === null) {
            throw new InvalidArgumentException('This order is not a delivery order.');
        }

        $allowed = self::DELIVERY_TRANSITIONS[$order->delivery_status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw new InvalidArgumentException(
                __('Cannot transition delivery from \':from\' to \':to\'.', [
                    'from' => $order->delivery_status,
                    'to' => $status,
                ]),
            );
        }

        $order->update(['delivery_status' => $status]);

        if ($status === 'delivered') {
            $order->update(['status' => 'completed']);
        }

        AuditLogger::log('delivery.status_updated', $order, ['delivery_status' => $status]);

        return $order->fresh(['payment.splits']);
    }
}
