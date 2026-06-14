<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'enterprise', 'status' => 'active']);
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

it('compares aggregator vs own channel revenue', function (): void {
    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'talabat',
        'total' => 1000.00,
    ]);

    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'dine_in',
        'total' => 500.00,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/analytics/aggregators')
        ->assertOk();

    expect((float) $response->json('data.totals.gross_revenue'))->toBe(1500.0);
    expect((float) $response->json('data.totals.estimated_commission'))->toBe(250.0);
    expect((float) $response->json('data.totals.net_revenue'))->toBe(1250.0);
    expect($response->json('data.comparison.aggregator_share_pct'))->toBe(66.7);
    expect($response->json('data.comparison.own_channels_share_pct'))->toBe(33.3);
});

it('calculates per-channel commission for elmenus', function (): void {
    Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'channel' => 'elmenus',
        'total' => 200.00,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/analytics/aggregators')
        ->assertOk();

    $elmenus = collect($response->json('data.channels'))
        ->firstWhere('label_ar', 'إلمينيو');

    expect($elmenus['commission_pct'])->toBe(20);
    expect((float) $elmenus['estimated_commission'])->toBe(40.0);
    expect((float) $elmenus['net_revenue'])->toBe(160.0);
});

it('blocks aggregator analytics on non-enterprise plans', function (): void {
    $growth = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    app()->instance('tenant', $growth);

    $user = User::factory()->create([
        'tenant_id' => $growth->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->getJson('/api/v1/analytics/aggregators')
        ->assertPaymentRequired();
});
