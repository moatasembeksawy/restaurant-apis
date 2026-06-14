<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Services;

use App\Models\User;
use App\Modules\POS\Orders\Models\Order;
use App\Shared\Support\Audit\AuditLogger;
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

        return $order->fresh(['rider']);
    }

    public function updateDeliveryStatus(Order $order, string $status): Order
    {
        if ($order->delivery_status === null) {
            throw new InvalidArgumentException('This order is not a delivery order.');
        }

        $allowed = self::DELIVERY_TRANSITIONS[$order->delivery_status] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot transition delivery from '{$order->delivery_status}' to '{$status}'.",
            );
        }

        $order->update(['delivery_status' => $status]);

        if ($status === 'delivered') {
            $order->update(['status' => 'completed']);
        }

        AuditLogger::log('delivery.status_updated', $order, ['delivery_status' => $status]);

        return $order->fresh();
    }
}
