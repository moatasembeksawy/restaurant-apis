<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Services;

use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Events\OrderPlaced;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use App\Shared\Support\Audit\AuditLogger;

class OrderPlacementService
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    /**
     * @param  array<int, array{menu_item_id: int, quantity: int, notes?: string|null}>  $items
     */
    public function place(
        int $branchId,
        string $channel,
        array $items,
        ?int $floorTableId = null,
        ?int $waiterId = null,
        ?int $customerId = null,
        ?string $notes = null,
        ?string $deliveryAddress = null,
        ?string $externalRef = null,
    ): Order {
        $this->planLimits->check('orders');

        $order = Order::create([
            'branch_id' => $branchId,
            'floor_table_id' => $floorTableId,
            'waiter_id' => $waiterId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'notes' => $notes,
            'delivery_address' => $deliveryAddress,
            'delivery_status' => in_array($channel, ['own_delivery', 'whatsapp'], true) ? 'pending' : null,
            'external_ref' => $externalRef,
            'status' => 'pending',
        ]);

        foreach ($items as $itemData) {
            $menuItem = MenuItem::findOrFail($itemData['menu_item_id']);

            if (! $menuItem->is_available) {
                throw new \InvalidArgumentException("Menu item {$menuItem->id} is unavailable.");
            }

            $quantity = (int) $itemData['quantity'];
            $subtotal = $menuItem->price * $quantity;

            $order->items()->create([
                'menu_item_id' => $menuItem->id,
                'item_name_ar' => $menuItem->name_ar,
                'unit_price' => $menuItem->price,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'status' => 'pending',
                'notes' => $itemData['notes'] ?? null,
            ]);
        }

        $order->recalculateTotals();

        if ($order->floor_table_id) {
            FloorTable::find($order->floor_table_id)?->update(['status' => 'occupied']);
        }

        $order->update(['status' => 'active']);

        broadcast(new OrderPlaced($order->load('items')))->toOthers();

        AuditLogger::log('order.placed', $order, [
            'channel' => $order->channel,
            'total' => $order->total,
            'items_count' => $order->items->count(),
        ]);

        return $order->load('items');
    }
}
