<?php

declare(strict_types=1);

use App\Modules\POS\Orders\Support\OrderFulfillment;

it('infers delivery fulfillment for aggregator channels', function (): void {
    expect(OrderFulfillment::resolve('talabat', null, null))->toBe('delivery');
    expect(OrderFulfillment::resolve('own_delivery', null, 'Nasr City'))->toBe('delivery');
});

it('infers takeaway for branch qr without a table', function (): void {
    expect(OrderFulfillment::resolve('qr', null, null))->toBe('takeaway');
});

it('infers dine-in when a table is linked', function (): void {
    expect(OrderFulfillment::resolve('qr', 12, null))->toBe('dine_in');
});

it('allows a delivery fee on delivery orders and zeros it otherwise', function (): void {
    expect(OrderFulfillment::normalizeDeliveryFee(OrderFulfillment::DELIVERY, 15.5))->toBe(15.5);
    expect(OrderFulfillment::normalizeDeliveryFee(OrderFulfillment::DINE_IN, null))->toBe(0.0);
});

it('rejects a positive delivery fee on non-delivery orders', function (): void {
    OrderFulfillment::normalizeDeliveryFee(OrderFulfillment::TAKEAWAY, 10);
})->throws(InvalidArgumentException::class, 'Delivery fees can only be applied');

it('rejects delivery without an address', function (): void {
    OrderFulfillment::validate(
        fulfillmentType: OrderFulfillment::DELIVERY,
        channel: 'qr',
        floorTableId: null,
        deliveryAddress: null,
    );
})->throws(InvalidArgumentException::class, 'delivery_address is required');
