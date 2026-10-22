<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offline\Hub\LocalHubStore;
use App\Modules\POS\Offline\Models\OfflineHub;
use App\Modules\POS\Offline\Support\OfflineOperations;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create([
        'plan' => 'pro',
        'status' => 'active',
    ]);
    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);
    $this->waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);
    $this->category = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
    ]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $this->category->id,
        'price' => 50,
        'is_available' => true,
    ]);
    FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->waiter
        ->createToken('offline-hub-test', ['orders:create'])
        ->plainTextToken;

    $this->hubPath = sys_get_temp_dir().'/pos-hub-'.uniqid('', true);
    $this->hubToken = 'hub-secret-token';

    Config::set('pos-hub.enabled', true);
    Config::set('pos-hub.token', $this->hubToken);
    Config::set('pos-hub.path', $this->hubPath);
    Config::set('pos-hub.branch_id', $this->branch->id);
    Config::set('pos-hub.tenant_id', $this->tenant->id);
    Config::set('pos-hub.port', 7744);

    app()->forgetInstance(LocalHubStore::class);
});

afterEach(function (): void {
    if (isset($this->hubPath) && is_dir($this->hubPath)) {
        File::deleteDirectory($this->hubPath);
    }
});

function hubHeaders(string $token): array
{
    return ['X-Hub-Token' => $token];
}

it('hides the LAN hub when it is disabled', function (): void {
    Config::set('pos-hub.enabled', false);

    $this->getJson('/api/v1/hub/health')
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'HUB_DISABLED');
});

it('exposes health without a hub token', function (): void {
    $this->getJson('/api/v1/hub/health')
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.port', 7744);
});

it('rejects kitchen routes without the hub token', function (): void {
    $this->getJson('/api/v1/hub/kitchen/queue')
        ->assertUnauthorized()
        ->assertJsonPath('errors.0.code', 'HUB_UNAUTHENTICATED');
});

it('lets POS and kitchen share an order on the LAN without the cloud', function (): void {
    $deviceId = (string) Str::uuid();
    $operationId = (string) Str::uuid();
    $clientOrderId = (string) Str::uuid();
    $clientItemId = (string) Str::uuid();

    $this->withHeaders(hubHeaders($this->hubToken))
        ->postJson('/api/v1/hub/events', [
            'device_id' => $deviceId,
            'role' => 'pos',
            'operations' => [[
                'operation_id' => $operationId,
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->toISOString(),
                'payload' => [
                    'client_order_id' => $clientOrderId,
                    'channel' => 'dine_in',
                    'items' => [[
                        'client_item_id' => $clientItemId,
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                        'item_name_ar' => 'كشري',
                    ]],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.events.0.operation_id', $operationId)
        ->assertJsonPath('data.kitchen_queue.0.local_number', 1)
        ->assertJsonPath('data.kitchen_queue.0.items.0.client_item_id', $clientItemId);

    $this->withHeaders(hubHeaders($this->hubToken))
        ->getJson('/api/v1/hub/kitchen/queue')
        ->assertOk()
        ->assertJsonPath('data.0.items.0.name_ar', 'كشري');

    $this->withHeaders(hubHeaders($this->hubToken))
        ->patchJson("/api/v1/hub/kitchen/items/{$clientItemId}/done", [
            'client_order_id' => $clientOrderId,
            'device_id' => (string) Str::uuid(),
            'operation_id' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->withHeaders(hubHeaders($this->hubToken))
        ->getJson('/api/v1/hub/events?after=0')
        ->assertOk()
        ->assertJsonPath('data.events.0.type', OfflineOperations::ORDER_CREATED)
        ->assertJsonPath('data.events.1.type', OfflineOperations::ITEM_READY);

    $this->withHeaders(hubHeaders($this->hubToken))
        ->postJson('/api/v1/hub/events', [
            'device_id' => $deviceId,
            'role' => 'pos',
            'operations' => [[
                'operation_id' => $operationId,
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->toISOString(),
                'payload' => [
                    'client_order_id' => $clientOrderId,
                    'items' => [[
                        'client_item_id' => $clientItemId,
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                    ]],
                ],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.events.0.duplicate', true);
});

it('caches a catalog snapshot for POS and kitchen tablets', function (): void {
    $this->withHeaders(hubHeaders($this->hubToken))
        ->getJson('/api/v1/hub/snapshot')
        ->assertNotFound()
        ->assertJsonPath('errors.0.code', 'HUB_SNAPSHOT_MISSING');

    $this->withHeaders(hubHeaders($this->hubToken))
        ->postJson('/api/v1/hub/snapshot', [
            'snapshot' => [
                'menu_items' => [[
                    'id' => $this->menuItem->id,
                    'name_ar' => $this->menuItem->name_ar,
                    'price' => 50,
                ]],
            ],
        ])
        ->assertOk();

    $this->withHeaders(hubHeaders($this->hubToken))
        ->getJson('/api/v1/hub/snapshot')
        ->assertOk()
        ->assertJsonPath('data.menu_items.0.id', $this->menuItem->id);
});

it('registers a branch hub and advertises it on bootstrap', function (): void {
    $created = $this->withToken($this->token)
        ->postJson('/api/v1/offline/hubs', [
            'branch_id' => $this->branch->id,
            'name' => 'Dining room hub',
        ])
        ->assertCreated()
        ->assertJsonPath('data.branch_id', $this->branch->id);

    $token = $created->json('data.token');
    $hubId = $created->json('data.id');

    expect($token)->toBeString()->not->toBeEmpty()
        ->and(OfflineHub::query()->where('branch_id', $this->branch->id)->exists())->toBeTrue();

    $this->withToken($this->token)
        ->postJson("/api/v1/offline/hubs/{$hubId}/heartbeat", [
            'lan_url' => 'http://192.168.1.20:7744',
        ])
        ->assertOk()
        ->assertJsonPath('data.lan_url', 'http://192.168.1.20:7744');

    $this->withToken($this->token)
        ->getJson("/api/v1/offline/bootstrap?branch_id={$this->branch->id}")
        ->assertOk()
        ->assertJsonPath('data.lan_hub.hub_id', $hubId)
        ->assertJsonPath('data.lan_hub.urls.0', 'http://192.168.1.20:7744');
});

it('uploads pending LAN operations to the cloud', function (): void {
    $operationId = (string) Str::uuid();
    $clientOrderId = (string) Str::uuid();

    $this->withHeaders(hubHeaders($this->hubToken))
        ->postJson('/api/v1/hub/events', [
            'device_id' => (string) Str::uuid(),
            'role' => 'pos',
            'operations' => [[
                'operation_id' => $operationId,
                'type' => OfflineOperations::ORDER_CREATED,
                'occurred_at' => now()->toISOString(),
                'payload' => [
                    'client_order_id' => $clientOrderId,
                    'channel' => 'own_delivery',
                    'fulfillment_type' => 'delivery',
                    'customer_name' => 'أحمد محمود',
                    'customer_phone' => '01012345678',
                    'delivery_address' => 'شارع التحرير، الدقي',
                    'items' => [[
                        'client_item_id' => (string) Str::uuid(),
                        'menu_item_id' => $this->menuItem->id,
                        'quantity' => 1,
                    ]],
                ],
            ]],
        ])
        ->assertOk();

    Config::set('pos-hub.cloud_url', 'https://cloud.example');
    Config::set('pos-hub.cloud_token', 'cloud-token');
    Config::set('pos-hub.cloud_subdomain', 'nile');

    Http::fake([
        'https://cloud.example/api/v1/offline/sync' => Http::response([
            'data' => [
                'results' => [[
                    'operation_id' => $operationId,
                    'disposition' => 'applied',
                ]],
            ],
        ], 200),
    ]);

    $this->withHeaders(hubHeaders($this->hubToken))
        ->postJson('/api/v1/hub/cloud-sync')
        ->assertOk()
        ->assertJsonPath('data.synced', 1);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://cloud.example/api/v1/offline/sync'
        && ($request['operations'][0]['payload']['customer_phone'] ?? null) === '01012345678'
        && ($request['operations'][0]['payload']['delivery_address'] ?? null) === 'شارع التحرير، الدقي'
        && ($request['operations'][0]['payload']['channel'] ?? null) === 'own_delivery');

    $this->artisan('pos:hub:sync')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('refuses to start the hub artisan server without configuration', function (): void {
    Config::set('pos-hub.enabled', false);

    $this->artisan('pos:hub')->assertFailed();
});
