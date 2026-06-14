<?php

declare(strict_types=1);

use App\Modules\Delivery\WhatsApp\Services\WhatsAppOrderService;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Event;
use App\Modules\POS\Orders\Events\OrderPlaced;

beforeEach(function (): void {
    Event::fake([OrderPlaced::class]);

    $this->tenant = Tenant::factory()->create([
        'plan' => 'growth',
        'status' => 'active',
        'whatsapp_phone_number_id' => '123456789',
    ]);

    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);
});

it('parses whatsapp order text format', function (): void {
    $service = app(WhatsAppOrderService::class);

    $items = $service->parseOrderText("ORDER\n{$this->menuItem->id}:2\n");

    expect($items)->toBe([
        ['menu_item_id' => $this->menuItem->id, 'quantity' => 2],
    ]);
});

it('creates order from whatsapp webhook payload', function (): void {
    app()->instance('tenant', $this->tenant);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => '123456789'],
                    'messages' => [[
                        'from' => '201012345678',
                        'id' => 'wamid.test123',
                        'text' => ['body' => "ORDER\n{$this->menuItem->id}:1"],
                    ]],
                ],
            ]],
        ]],
    ];

    $this->postJson('/api/v1/webhook/whatsapp', $payload)
        ->assertOk()
        ->assertJsonPath('status', 'received');

    expect(Order::query()->where('channel', 'whatsapp')->count())->toBe(1);
});

it('verifies whatsapp webhook challenge', function (): void {
    config(['services.whatsapp.verify_token' => 'test-verify-token']);

    $this->getJson('/api/v1/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=challenge123')
        ->assertOk()
        ->assertSee('challenge123');
});
