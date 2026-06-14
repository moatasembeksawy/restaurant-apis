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

it('rejects delivery without an address', function (): void {
    OrderFulfillment::validate(
        fulfillmentType: OrderFulfillment::DELIVERY,
        channel: 'qr',
        floorTableId: null,
        deliveryAddress: null,
    );
})->throws(InvalidArgumentException::class, 'delivery_address is required');
