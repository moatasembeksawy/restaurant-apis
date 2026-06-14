<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Billing\Models\PaymentSplit;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 150.00,
    ]);

    $this->table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'floor_table_id' => $this->table->id,
        'status' => 'ready',
        'subtotal' => 150.00,
        'total' => 150.00,
    ]);

    OrderItem::create([
        'order_id' => $this->order->id,
        'menu_item_id' => $menuItem->id,
        'item_name_ar' => $menuItem->name_ar,
        'unit_price' => 150.00,
        'quantity' => 1,
        'subtotal' => 150.00,
        'status' => 'ready',
    ]);

    app()->instance('tenant', $this->tenant);
    startCashierShift($this->cashier);
    $this->token = $this->cashier->createToken('test', ['billing:*'])->plainTextToken;
});

it('settles split payment across multiple methods', function (): void {
    Queue::fake();

    $response = $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'split',
            'amount' => 150.00,
            'splits' => [
                ['method' => 'cash', 'amount' => 100.00],
                ['method' => 'card', 'amount' => 50.00, 'reference' => 'TXN-99'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.payment.method', 'split');

    expect($this->order->fresh()->status)->toBe('paid');
    expect(PaymentSplit::query()->count())->toBe(2);
    expect((float) PaymentSplit::query()->sum('amount'))->toBe(150.0);

    Queue::assertPushed(SubmitETAInvoiceJob::class);
});

it('rejects split payment when amounts do not match order total', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'split',
            'amount' => 150.00,
            'splits' => [
                ['method' => 'cash', 'amount' => 80.00],
                ['method' => 'card', 'amount' => 50.00],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'PAYMENT_FAILED');
});

it('rejects split payment with fewer than two methods', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/orders/{$this->order->id}/pay", [
            'method' => 'split',
            'amount' => 150.00,
            'splits' => [
                ['method' => 'cash', 'amount' => 150.00],
            ],
        ])
        ->assertUnprocessable();
});
