<?php

declare(strict_types=1);

use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'growth',
        'status' => 'active',
        'subdomain' => 'talabat-test',
        'talabat_webhook_secret' => 'talabat-secret',
        'elmenus_webhook_secret' => 'elmenus-secret',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 120.00,
        'is_available' => true,
    ]);
});

function signedAggregatorPayload(array $payload, string $secret): array
{
    $json = json_encode($payload);

    return [
        'body' => $json,
        'signature' => hash_hmac('sha256', $json, $secret),
    ];
}

function postAggregatorWebhook(string $channel, array $payload, string $subdomain, string $signature): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        "/api/v1/webhook/aggregators/{$channel}",
        [],
        [],
        [],
        test()->transformHeadersToServerVars([
            'X-Tenant-Subdomain' => $subdomain,
            'X-Webhook-Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]),
        $body,
    );
}

it('creates talabat order from signed webhook', function (): void {
    $payload = [
        'external_order_id' => 'TAL-1001',
        'branch_id' => $this->branch->id,
        'customer_phone' => '01098765432',
        'customer_name' => 'سارة',
        'delivery_address' => 'مدينة نصر',
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 2],
        ],
    ];

    $signed = signedAggregatorPayload($payload, 'talabat-secret');

    postAggregatorWebhook('talabat', $payload, 'talabat-test', $signed['signature'])
        ->assertOk()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.external_ref', 'TAL-1001');

    $order = Order::query()->where('external_ref', 'TAL-1001')->first();

    expect($order)->not->toBeNull();
    expect($order->channel)->toBe('talabat');
    expect($order->delivery_status)->toBe('pending');
    expect((float) $order->total)->toBe(240.0);
});

it('is idempotent for duplicate aggregator orders', function (): void {
    $payload = [
        'external_order_id' => 'TAL-2002',
        'branch_id' => $this->branch->id,
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
    ];

    $signed = signedAggregatorPayload($payload, 'talabat-secret');

    postAggregatorWebhook('talabat', $payload, 'talabat-test', $signed['signature'])
        ->assertOk()
        ->assertJsonPath('data.created', true);

    postAggregatorWebhook('talabat', $payload, 'talabat-test', $signed['signature'])
        ->assertOk()
        ->assertJsonPath('data.created', false);

    expect(Order::query()->where('external_ref', 'TAL-2002')->count())->toBe(1);
});

it('rejects invalid webhook signature', function (): void {
    $payload = [
        'external_order_id' => 'TAL-3003',
        'branch_id' => $this->branch->id,
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
    ];

    $signed = signedAggregatorPayload($payload, 'talabat-secret');

    postAggregatorWebhook('talabat', $payload, 'talabat-test', 'invalid-signature')
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'INVALID_SIGNATURE');
});

it('accepts elmenus webhook with correct signature', function (): void {
    $payload = [
        'external_order_id' => 'ELM-5005',
        'branch_id' => $this->branch->id,
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
    ];

    $signed = signedAggregatorPayload($payload, 'elmenus-secret');

    postAggregatorWebhook('elmenus', $payload, 'talabat-test', $signed['signature'])
        ->assertOk()
        ->assertJsonPath('data.created', true);

    expect(Order::query()->where('channel', 'elmenus')->count())->toBe(1);
});

it('blocks aggregator webhooks when delivery feature is unavailable', function (): void {
    $this->tenant->update(['plan' => 'starter']);

    $payload = [
        'external_order_id' => 'TAL-4004',
        'branch_id' => $this->branch->id,
        'items' => [
            ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
        ],
    ];

    $signed = signedAggregatorPayload($payload, 'talabat-secret');

    postAggregatorWebhook('talabat', $payload, 'talabat-test', $signed['signature'])
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
