<?php

declare(strict_types=1);

namespace App\Modules\POS\Orders\Services;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\WhatsApp\Jobs\SendWhatsAppNotificationJob;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Events\OrderPlaced;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Support\OrderCharges;
use App\Modules\POS\Orders\Support\OrderDeliveryDestination;
use App\Modules\POS\Orders\Support\OrderFulfillment;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Broadcasting\SafeBroadcast;
use InvalidArgumentException;

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
        ?string $fulfillmentType = null,
        ?float $deliveryFee = null,
        bool $deliveryFeeProvided = false,
        ?int $customerAddressId = null,
        ?int $districtId = null,
    ): Order {
        $this->planLimits->check('orders');

        $destination = OrderDeliveryDestination::resolve(
            incoming: array_filter([
                'customer_id' => $customerId,
                'customer_address_id' => $customerAddressId,
                'district_id' => $districtId,
                'delivery_address' => $deliveryAddress,
            ], fn (mixed $value): bool => $value !== null),
            current: [
                'customer_id' => null,
                'customer_address_id' => null,
                'district_id' => null,
                'delivery_address' => null,
            ],
        );

        $customerId = $destination['customer_id'];
        $customerAddressId = $destination['customer_address_id'];
        $districtId = $destination['district_id'];
        $deliveryAddress = $destination['delivery_address'];
        $deliveryFee = OrderDeliveryDestination::fee(
            $deliveryFee,
            $deliveryFeeProvided || $deliveryFee !== null,
            $destination['suggested_fee'],
        );

        $fulfillmentType = OrderFulfillment::resolve(
            channel: $channel,
            floorTableId: $floorTableId,
            deliveryAddress: $deliveryAddress,
            fulfillmentType: $fulfillmentType,
        );

        OrderFulfillment::validate(
            fulfillmentType: $fulfillmentType,
            channel: $channel,
            floorTableId: $floorTableId,
            deliveryAddress: $deliveryAddress,
        );

        $deliveryFee = OrderFulfillment::normalizeDeliveryFee($fulfillmentType, $deliveryFee);

        /** @var Tenant $tenant */
        $tenant = app('tenant');

        if ($customerId !== null && Customer::query()->whereKey($customerId)->doesntExist()) {
            throw new InvalidArgumentException('Customer not found.');
        }

        if (
            $fulfillmentType === OrderFulfillment::DELIVERY
            && $channel === 'qr'
            && ! $tenant->hasFeature('delivery')
        ) {
            throw new InvalidArgumentException('Delivery is not enabled for this restaurant.');
        }

        $charges = OrderCharges::resolve($tenant, Branch::query()->find($branchId));

        $order = Order::create([
            'branch_id' => $branchId,
            'floor_table_id' => $floorTableId,
            'waiter_id' => $waiterId,
            'customer_id' => $customerId,
            'customer_address_id' => $customerAddressId,
            'district_id' => $districtId,
            'channel' => $channel,
            'fulfillment_type' => $fulfillmentType,
            'notes' => $notes,
            'delivery_address' => $deliveryAddress,
            'delivery_fee' => $deliveryFee,
            'tax_rate' => $charges['tax_rate'],
            'service_charge_rate' => $charges['service_charge_rate'],
            'service_charge_applies_to' => $charges['service_charge_applies_to'],
            'delivery_status' => OrderFulfillment::requiresDeliveryTracking($fulfillmentType) ? 'pending' : null,
            'external_ref' => $externalRef,
            'status' => 'pending',
        ]);

        foreach ($items as $itemData) {
            $menuItem = MenuItem::findOrFail($itemData['menu_item_id']);

            if (! $menuItem->is_available) {
                throw new InvalidArgumentException("Menu item {$menuItem->id} is unavailable.");
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

        SafeBroadcast::toOthers(new OrderPlaced($order->load('items')));

        AuditLogger::log('order.placed', $order, [
            'channel' => $order->channel,
            'fulfillment_type' => $order->fulfillment_type,
            'total' => $order->total,
            'items_count' => $order->items->count(),
        ]);

        if (
            in_array($order->channel, ['whatsapp', 'qr', 'own_delivery'], true)
            && $order->customer_id
            && $tenant->hasFeature('whatsapp_ordering')
            && $tenant->whatsapp_phone_number_id
        ) {
            SendWhatsAppNotificationJob::dispatch($order->load('customer'), 'order_confirmed');
        }

        return $order->load(['items', 'district', 'customerAddress.district']);
    }
}
