<?php

declare(strict_types=1);

use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Support\OrderCharges;
use App\Modules\POS\Orders\Support\OrderFulfillment;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

it('inherits tax and service charge rates from the tenant', function (): void {
    $tenant = new Tenant([
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
    ]);
    $branch = new Branch([
        'tax_rate' => null,
        'tax_rate_applies_to' => null,
        'service_charge_rate' => null,
        'service_charge_applies_to' => null,
    ]);

    expect(OrderCharges::resolve($tenant, $branch))->toBe([
        'tax_rate' => 14.0,
        'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
        'service_charge_rate' => 12.0,
        'service_charge_applies_to' => ['dine_in'],
    ]);
});

it('lets a branch override tenant tax and service charge rates', function (): void {
    $tenant = new Tenant([
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
    ]);
    $branch = new Branch([
        'tax_rate' => 0,
        'tax_rate_applies_to' => ['dine_in'],
        'service_charge_rate' => 10,
        'service_charge_applies_to' => ['dine_in', 'takeaway'],
    ]);

    expect(OrderCharges::resolve($tenant, $branch))->toMatchArray([
        'tax_rate' => 0.0,
        'tax_rate_applies_to' => ['dine_in'],
        'service_charge_rate' => 10.0,
        'service_charge_applies_to' => ['dine_in', 'takeaway'],
    ]);
});

it('applies service charge only to configured fulfillment types', function (): void {
    $dineIn = new Order([
        'subtotal' => 100,
        'discount' => 0,
        'delivery_fee' => 0,
        'tax_rate' => 14,
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
        'fulfillment_type' => OrderFulfillment::DINE_IN,
    ]);

    $takeaway = new Order([
        'subtotal' => 100,
        'discount' => 0,
        'delivery_fee' => 0,
        'tax_rate' => 14,
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
        'fulfillment_type' => OrderFulfillment::TAKEAWAY,
    ]);

    expect(OrderCharges::compute($dineIn))->toBe([
        'service_charge' => 12.0,
        'tax' => 15.68,
        'total' => 127.68,
    ]);

    expect(OrderCharges::compute($takeaway))->toBe([
        'service_charge' => 0.0,
        'tax' => 14.0,
        'total' => 114.0,
    ]);
});

it('applies tax only to configured fulfillment types', function (): void {
    $dineIn = new Order([
        'subtotal' => 100,
        'discount' => 0,
        'delivery_fee' => 0,
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in'],
        'service_charge_rate' => 0,
        'fulfillment_type' => OrderFulfillment::DINE_IN,
    ]);

    $takeaway = new Order([
        'subtotal' => 100,
        'discount' => 0,
        'delivery_fee' => 0,
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in'],
        'service_charge_rate' => 0,
        'fulfillment_type' => OrderFulfillment::TAKEAWAY,
    ]);

    expect(OrderCharges::compute($dineIn))->toBe([
        'service_charge' => 0.0,
        'tax' => 14.0,
        'total' => 114.0,
    ]);

    expect(OrderCharges::compute($takeaway))->toBe([
        'service_charge' => 0.0,
        'tax' => 0.0,
        'total' => 100.0,
    ]);
});
