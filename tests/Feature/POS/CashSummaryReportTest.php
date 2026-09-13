<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentSplit;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Carbon\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-13 12:00:00');

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rolls split payments into each child method', function (): void {
    $cashOrder = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 200.00,
        'created_at' => now(),
    ]);

    Payment::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $cashOrder->id,
        'cashier_id' => $this->manager->id,
        'method' => 'cash',
        'amount' => 200.00,
    ]);

    $splitOrder = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 150.00,
        'created_at' => now(),
    ]);

    $splitPayment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $splitOrder->id,
        'cashier_id' => $this->manager->id,
        'method' => 'split',
        'amount' => 150.00,
    ]);

    PaymentSplit::create([
        'payment_id' => $splitPayment->id,
        'method' => 'cash',
        'amount' => 100.00,
    ]);

    PaymentSplit::create([
        'payment_id' => $splitPayment->id,
        'method' => 'card',
        'amount' => 50.00,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/reports/cash-summary?date=2026-09-13')
        ->assertOk();

    expect($response->json('data.by_method'))->not->toHaveKey('split');
    expect($response->json('data.by_method.cash.count'))->toBe(2);
    expect((float) $response->json('data.by_method.cash.total'))->toBe(300.0);
    expect($response->json('data.by_method.card.count'))->toBe(1);
    expect((float) $response->json('data.by_method.card.total'))->toBe(50.0);
    expect((float) $response->json('data.total_cash'))->toBe(300.0);
    expect((float) $response->json('data.total_all_methods'))->toBe(350.0);
});
