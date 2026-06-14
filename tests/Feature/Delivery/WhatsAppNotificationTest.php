<?php

declare(strict_types=1);

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\WhatsApp\Jobs\SendWhatsAppNotificationJob;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'services.whatsapp.access_token' => 'test-token',
        'services.whatsapp.webhook_secret' => 'secret',
        'whatsapp.templates.order_confirmed' => 'order_confirmed',
        'whatsapp.language' => 'ar',
    ]);

    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200),
    ]);

    $this->tenant = Tenant::factory()->create([
        'plan' => 'growth',
        'status' => 'active',
        'whatsapp_phone_number_id' => '123456789',
    ]);

    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '+201012345678',
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->menuItem = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'price' => 85.00,
        'is_available' => true,
    ]);

    app()->instance('tenant', $this->tenant);
});

it('dispatches whatsapp confirmation when qr order is placed', function (): void {
    Queue::fake();

    app(OrderPlacementService::class)->place(
        branchId: $this->branch->id,
        channel: 'qr',
        items: [['menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
        customerId: $this->customer->id,
    );

    Queue::assertPushed(SendWhatsAppNotificationJob::class, fn ($job) => $job->type === 'order_confirmed');
});

it('sends whatsapp template via notification job', function (): void {
    $order = Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'channel' => 'whatsapp',
        'total' => 85.00,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $this->menuItem->id,
        'item_name_ar' => $this->menuItem->name_ar,
        'unit_price' => 85.00,
        'quantity' => 1,
        'subtotal' => 85.00,
        'status' => 'pending',
    ]);

    $job = new SendWhatsAppNotificationJob($order->load('customer', 'tenant'), 'order_confirmed');
    $job->handle(app(\App\Modules\Delivery\WhatsApp\Services\WhatsAppNotificationService::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
        && $request->data()['template']['name'] === 'order_confirmed');
});

it('skips whatsapp when tenant has no phone number id', function (): void {
    Queue::fake();

    $this->tenant->update(['whatsapp_phone_number_id' => null]);
    app()->instance('tenant', $this->tenant->fresh());

    app(OrderPlacementService::class)->place(
        branchId: $this->branch->id,
        channel: 'qr',
        items: [['menu_item_id' => $this->menuItem->id, 'quantity' => 1]],
        customerId: $this->customer->id,
    );

    Queue::assertNotPushed(SendWhatsAppNotificationJob::class);
});
