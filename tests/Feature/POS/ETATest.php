<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Billing\Jobs\SubmitETAInvoiceJob;
use App\Modules\POS\Billing\Models\Invoice;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'services.eta.client_id' => 'global-client',
        'services.eta.client_secret' => 'global-secret',
    ]);

    $this->tenant = Tenant::factory()->create([
        'plan' => 'starter',
        'status' => 'active',
        'eta_client_id' => 'tenant-client',
        'eta_client_secret' => 'tenant-secret',
        'eta_taxpayer_id' => 'TX-123',
    ]);

    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    $order = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 100,
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'cashier_id' => $this->owner->id,
        'method' => 'cash',
        'amount' => 100,
    ]);

    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'payment_id' => $payment->id,
        'eta_status' => 'failed',
        'eta_response' => ['error' => 'Timeout'],
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('shows eta settings for owner without exposing secret', function (): void {
    $this->withToken($this->token)
        ->getJson('/api/v1/settings/eta')
        ->assertOk()
        ->assertJsonPath('data.eta_client_id', 'tenant-client')
        ->assertJsonPath('data.has_client_secret', true)
        ->assertJsonMissing(['eta_client_secret']);
});

it('updates tenant eta credentials', function (): void {
    $this->withToken($this->token)
        ->patchJson('/api/v1/settings/eta', [
            'eta_taxpayer_id' => 'TX-999',
            'eta_branch_id' => '2',
        ])
        ->assertOk()
        ->assertJsonPath('data.eta_taxpayer_id', 'TX-999')
        ->assertJsonPath('data.eta_branch_id', '2');
});

it('resubmits a failed eta invoice', function (): void {
    Queue::fake();

    $this->withToken($this->token)
        ->postJson("/api/v1/invoices/{$this->invoice->id}/resubmit")
        ->assertOk()
        ->assertJsonPath('data.eta_status', 'pending');

    Queue::assertPushed(SubmitETAInvoiceJob::class);
});

it('lists failed invoices', function (): void {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/invoices/failed')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.eta_status'))->toBe('failed');
});
