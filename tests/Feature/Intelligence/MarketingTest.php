<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Marketing\Jobs\SendMarketingMessageJob;
use App\Modules\Intelligence\Marketing\Models\MarketingCampaign;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'enterprise',
        'status' => 'active',
        'whatsapp_phone_number_id' => '123456789',
    ]);

    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '01011112222',
        'last_order_at' => now()->subDays(3),
    ]);

    Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '01033334444',
        'last_order_at' => now()->subDays(45),
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('lists available marketing segments', function (): void {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/marketing/segments')
        ->assertOk();

    expect($response->json('data.segments'))->toContain('all', 'inactive_30d', 'recent_visitors');
});

it('queues marketing broadcast for a segment', function (): void {
    Queue::fake();

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/marketing/broadcast', [
            'template_name' => 'promo_offer',
            'segment' => 'recent_visitors',
            'parameters' => ['Summer Sale'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.template_name', 'promo_offer')
        ->assertJsonPath('data.segment', 'recent_visitors')
        ->assertJsonPath('data.recipients_count', 1);

    expect(MarketingCampaign::query()->count())->toBe(1);

    Queue::assertPushed(SendMarketingMessageJob::class, 1);
});

it('rejects broadcast when no customers match segment', function (): void {
    Customer::query()->update(['total_spent' => 0]);

    $this->withToken($this->token)
        ->postJson('/api/v1/marketing/broadcast', [
            'template_name' => 'promo_offer',
            'segment' => 'high_spenders',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'MARKETING_BROADCAST_FAILED');
});

it('blocks marketing on non-enterprise plans', function (): void {
    $pro = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    app()->instance('tenant', $pro);

    $user = User::factory()->create([
        'tenant_id' => $pro->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/v1/marketing/broadcast', [
            'template_name' => 'promo_offer',
            'segment' => 'all',
        ])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});

it('forbids waiters from sending broadcasts', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->withToken($waiter->createToken('test')->plainTextToken)
        ->postJson('/api/v1/marketing/broadcast', [
            'template_name' => 'promo_offer',
            'segment' => 'all',
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});
