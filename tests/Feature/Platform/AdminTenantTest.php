<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Intelligence\Loyalty\Models\LoyaltyTransaction;
use App\Modules\Inventory\Stock\Models\Ingredient;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrder;
use App\Modules\Inventory\Suppliers\Models\PurchaseOrderItem;
use App\Modules\Inventory\Suppliers\Models\Supplier;
use App\Modules\Platform\Models\PlatformAdmin;
use App\Modules\Platform\Services\TenantManagementService;
use App\Modules\POS\Billing\Models\Invoice;
use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Billing\Models\PaymentSplit;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Offers\Models\OfferRedemption;
use App\Modules\POS\Offers\Models\OrderAdjustment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Models\OrderItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Modules\Tenant\Finance\Models\Expense;
use App\Modules\Tenant\Finance\Models\ExpenseCategory;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Services\StaffService;
use App\Modules\Tenant\Staff\Models\StaffShift;

beforeEach(function (): void {
    $this->admin = PlatformAdmin::create([
        'name' => 'Platform Admin',
        'email' => 'admin@restoapp.eg',
        'password' => 'password',
        'is_active' => true,
    ]);

    $this->token = $this->admin->createToken('test', ['platform:*'])->plainTextToken;

    $this->tenant = Tenant::factory()->create([
        'plan' => 'growth',
        'status' => 'trial',
        'subdomain' => 'cairo-bistro',
    ]);

    Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);

    User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'owner',
        'email' => 'owner@cairo.eg',
        'is_active' => true,
    ]);
});

it('lists tenants with filters', function (): void {
    Tenant::factory()->create(['plan' => 'pro', 'status' => 'active', 'subdomain' => 'pro-shop']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/tenants?status=trial')
        ->assertOk();

    expect(collect($response->json('data'))->every(fn ($t) => $t['status'] === 'trial'))->toBeTrue();
});

it('shows tenant details for admin', function (): void {
    $response = $this->withToken($this->token)
        ->getJson("/api/v1/admin/tenants/{$this->tenant->id}")
        ->assertOk();

    expect($response->json('data.subdomain'))->toBe('cairo-bistro');
    expect($response->json('data.owner.email'))->toBe('owner@cairo.eg');
    expect($response->json('data.limits.max_users'))->toBe(5);
});

it('creates a tenant from admin panel', function (): void {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/tenants', [
            'restaurant_name' => 'Giza Grill',
            'subdomain' => 'giza-grill',
            'owner_name' => 'Ahmed',
            'owner_email' => 'ahmed@giza.eg',
            'owner_password' => 'password123',
            'plan' => 'pro',
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.status', 'active');

    expect(Tenant::query()->where('subdomain', 'giza-grill')->exists())->toBeTrue();
});

it('updates tenant plan and status', function (): void {
    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan' => 'enterprise'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'enterprise');

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');

    expect($this->tenant->fresh()->status)->toBe('suspended');
    expect($staff->fresh()->is_active)->toBeFalse();

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');

    expect($staff->fresh()->is_active)->toBeTrue();
});

it('only reactivates tenant-suspended users when unsuspending', function (): void {
    $owner = User::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('role', 'owner')
        ->first();

    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $manuallyDeactivated = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    app(StaffService::class)->deactivate($manuallyDeactivated, $owner);

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'suspended'])
        ->assertOk();

    expect($staff->fresh()->is_active)->toBeFalse();
    expect($staff->fresh()->deactivation_reason)->toBe('tenant_suspended');
    expect($manuallyDeactivated->fresh()->is_active)->toBeFalse();
    expect($manuallyDeactivated->fresh()->deactivation_reason)->toBe('manual');

    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/status", ['status' => 'active'])
        ->assertOk();

    expect($staff->fresh()->is_active)->toBeTrue();
    expect($manuallyDeactivated->fresh()->is_active)->toBeFalse();
});

it('updates tenant feature flags', function (): void {
    $this->withToken($this->token)
        ->patchJson("/api/v1/admin/tenants/{$this->tenant->id}/features", [
            'feature_flags' => ['loyalty', 'ai_reports'],
        ])
        ->assertOk()
        ->assertJsonPath('data.feature_flags', ['loyalty', 'ai_reports']);

    expect($this->tenant->fresh()->hasFeature('loyalty'))->toBeTrue();
});

it('returns platform dashboard stats', function (): void {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk();

    expect($response->json('data.tenants.total'))->toBeGreaterThan(0);
    expect($response->json('data.revenue'))->toHaveKeys(['mrr_egp', 'active_subscriptions']);
});

it('issues impersonation token for tenant owner', function (): void {
    $result = app(TenantManagementService::class)
        ->impersonate($this->tenant, $this->admin);

    expect($result['user']['email'])->toBe('owner@cairo.eg');
    expect($result['impersonated_by']['email'])->toBe('admin@restoapp.eg');
    expect($result['token'])->not->toBeEmpty();

    $this->withToken($result['token'])
        ->getJson('/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('data.plan', 'growth');
});

it('deletes tenant financial data and keeps menu and tables', function (): void {
    $branch = Branch::query()->where('tenant_id', $this->tenant->id)->first();
    $owner = User::query()->where('tenant_id', $this->tenant->id)->where('role', 'owner')->first();

    $menuCategory = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $item = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $menuCategory->id,
    ]);
    $table = FloorTable::factory()->occupied()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
    ]);
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'loyalty_points' => 40,
        'visit_count' => 3,
        'total_spent' => 250,
        'last_order_at' => now(),
    ]);
    $order = Order::factory()->paid()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'floor_table_id' => $table->id,
        'customer_id' => $customer->id,
        'total' => 100,
    ]);
    $parentItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'line_type' => 'package',
        'menu_item_id' => $item->id,
        'item_name_ar' => 'وجبة',
        'unit_price' => 100,
        'quantity' => 1,
        'subtotal' => 100,
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id,
        'line_type' => 'package_component',
        'parent_id' => $parentItem->id,
        'menu_item_id' => $item->id,
        'item_name_ar' => 'طبق',
        'unit_price' => 0,
        'quantity' => 1,
        'subtotal' => 0,
    ]);

    $shift = StaffShift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'user_id' => $owner->id,
        'clock_in' => now(),
        'opening_float' => 200,
    ]);
    $movement = CashMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'staff_shift_id' => $shift->id,
        'created_by' => $owner->id,
        'type' => 'paid_in',
        'amount' => 50,
        'reason' => 'float',
        'occurred_at' => now(),
    ]);
    CashMovement::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'staff_shift_id' => $shift->id,
        'created_by' => $owner->id,
        'reversal_of_id' => $movement->id,
        'type' => 'reversal',
        'amount' => 50,
        'reason' => 'undo',
        'occurred_at' => now(),
    ]);

    $expenseCategory = ExpenseCategory::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Supplies',
        'code' => 'supplies',
    ]);
    Expense::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'expense_category_id' => $expenseCategory->id,
        'staff_shift_id' => $shift->id,
        'created_by' => $owner->id,
        'amount' => 30,
        'payment_method' => 'cash',
        'description' => 'napkins',
        'expense_date' => now()->toDateString(),
    ]);

    $payment = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $order->id,
        'cashier_id' => $owner->id,
        'staff_shift_id' => $shift->id,
        'method' => 'split',
        'amount' => 100,
    ]);
    PaymentSplit::query()->create([
        'payment_id' => $payment->id,
        'method' => 'cash',
        'amount' => 100,
    ]);
    Invoice::query()->create([
        'tenant_id' => $this->tenant->id,
        'payment_id' => $payment->id,
        'eta_status' => 'pending',
    ]);

    $offer = Offer::factory()->create(['tenant_id' => $this->tenant->id]);
    OfferRedemption::query()->create([
        'offer_id' => $offer->id,
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'amount' => 10,
    ]);
    OrderAdjustment::query()->create([
        'order_id' => $order->id,
        'source' => 'manual',
        'amount' => 5,
        'label' => 'rounding',
    ]);
    LoyaltyTransaction::query()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'order_id' => $order->id,
        'type' => 'earn',
        'points' => 10,
        'balance_after' => 40,
    ]);

    $supplier = Supplier::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Fresh Farm',
    ]);
    $ingredient = Ingredient::factory()->create(['tenant_id' => $this->tenant->id]);
    $purchaseOrder = PurchaseOrder::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'created_by' => $owner->id,
        'status' => 'received',
        'total' => 80,
    ]);
    PurchaseOrderItem::query()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'ingredient_id' => $ingredient->id,
        'quantity' => 2,
        'unit_cost' => 40,
        'subtotal' => 80,
    ]);

    $otherTenant = Tenant::factory()->create(['subdomain' => 'other-shop']);
    $otherBranch = Branch::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherOrder = Order::factory()->create([
        'tenant_id' => $otherTenant->id,
        'branch_id' => $otherBranch->id,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/financial-data", [
            'confirm' => 'wrong-name',
        ])
        ->assertStatus(422);

    expect(Order::query()->whereKey($order->id)->exists())->toBeTrue();

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/financial-data", [
            'confirm' => $this->tenant->subdomain,
        ])
        ->assertOk()
        ->assertJsonPath('data.deleted.orders', 1)
        ->assertJsonPath('data.deleted.payments', 1)
        ->assertJsonPath('data.deleted.staff_shifts', 1)
        ->assertJsonPath('data.deleted.expenses', 1)
        ->assertJsonPath('data.deleted.purchase_orders', 1)
        ->assertJsonPath('data.reset.tables_freed', 1)
        ->assertJsonPath('meta.message', 'Tenant financial data deleted. Menu, tables, and setup data were kept.');

    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse();
    expect(OrderItem::query()->where('order_id', $order->id)->exists())->toBeFalse();
    expect(Payment::query()->whereKey($payment->id)->exists())->toBeFalse();
    expect(StaffShift::query()->whereKey($shift->id)->exists())->toBeFalse();
    expect(Expense::query()->where('tenant_id', $this->tenant->id)->exists())->toBeFalse();
    expect(PurchaseOrder::query()->whereKey($purchaseOrder->id)->exists())->toBeFalse();
    expect(LoyaltyTransaction::query()->where('tenant_id', $this->tenant->id)->exists())->toBeFalse();

    expect(MenuItem::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(MenuCategory::query()->whereKey($menuCategory->id)->exists())->toBeTrue();
    expect($table->fresh()->status)->toBe('free');
    expect($table->fresh()->name)->not->toBeEmpty();
    expect($customer->fresh()->loyalty_points)->toBe(0);
    expect((float) $customer->fresh()->total_spent)->toBe(0.0);
    expect($customer->fresh()->visit_count)->toBe(0);
    expect($customer->fresh()->last_order_at)->toBeNull();
    expect(ExpenseCategory::query()->whereKey($expenseCategory->id)->exists())->toBeTrue();
    expect(Offer::query()->whereKey($offer->id)->exists())->toBeTrue();
    expect(Supplier::query()->whereKey($supplier->id)->exists())->toBeTrue();
    expect(Ingredient::query()->whereKey($ingredient->id)->exists())->toBeTrue();
    expect($owner->fresh()->is_active)->toBeTrue();
    expect($branch->fresh())->not->toBeNull();
    expect($otherOrder->fresh())->not->toBeNull();
});

it('issues impersonation token via admin api', function (): void {
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/impersonate")
        ->assertOk()
        ->assertJsonPath('data.user.email', 'owner@cairo.eg')
        ->assertJsonPath('data.impersonated_by.email', 'admin@restoapp.eg')
        ->assertJsonStructure(['data' => ['token', 'expires_at', 'tenant']]);
});
