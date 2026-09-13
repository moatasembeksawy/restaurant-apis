<?php

declare(strict_types=1);

use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Infrastructure\PrintJob\EscPosBuilder;
use Illuminate\Support\Carbon;

it('prints discount before service and tax so the receipt matches after-discount charges', function (): void {
    $order = new Order([
        'subtotal' => 100,
        'discount' => 20,
        'service_charge' => 9.60,
        'tax' => 12.54,
        'delivery_fee' => 0,
        'total' => 102.14,
    ]);
    $order->id = 1;
    $order->setRelation('items', collect([
        new OrderItem([
            'quantity' => 1,
            'item_name_ar' => 'برجر',
            'subtotal' => 100,
        ]),
    ]));

    $payment = new Payment([
        'method' => 'cash',
    ]);
    $payment->created_at = Carbon::parse('2026-09-13 14:00:00');
    $payment->setRelation('invoice', null);

    $receipt = (new EscPosBuilder)->buildReceipt(
        $order,
        $payment,
        new Tenant(['name' => 'Test Restaurant']),
        new Branch(['name' => 'Main', 'name_ar' => 'الرئيسي', 'address' => 'Cairo']),
    );

    expect(strpos($receipt, 'Subtotal:'))->toBeLessThan(strpos($receipt, 'Discount:'));
    expect(strpos($receipt, 'Discount:'))->toBeLessThan(strpos($receipt, 'Service:'));
    expect(strpos($receipt, 'Service:'))->toBeLessThan(strpos($receipt, 'Tax:'));
});
