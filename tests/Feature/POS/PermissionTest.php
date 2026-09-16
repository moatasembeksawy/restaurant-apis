<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->cook = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cook',
        'is_active' => true,
    ]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
});

it('allows waiter to read menu but not create categories', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $token = $waiter->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/menu/categories')
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/menu/categories', [
            'name_ar' => 'مشروبات',
            'name_en' => 'Drinks',
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('blocks cook from creating menu categories', function (): void {
    $token = $this->cook->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/menu/categories', [
            'name_ar' => 'مشروبات',
            'name_en' => 'Drinks',
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('allows manager to manage menu categories', function (): void {
    $token = $this->manager->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/menu/categories', [
            'name_ar' => 'حلويات',
            'name_en' => 'Desserts',
        ])
        ->assertCreated();
});

it('blocks waiter from daily reports', function (): void {
    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->withToken($waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/reports/daily')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('allows cashier to access daily reports', function (): void {
    $cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->withToken($cashier->createToken('test')->plainTextToken)
        ->getJson('/api/v1/reports/daily')
        ->assertOk();
});

it('allows manager to view offers', function (): void {
    $this->withToken($this->manager->createToken('test')->plainTextToken)
        ->getJson('/api/v1/offers')
        ->assertOk();
});

it('returns forbidden instead of crashing when a permission is missing', function (): void {
    Permission::query()->where('name', 'menu.view')->delete();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $waiter = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'is_active' => true,
    ]);

    $this->withToken($waiter->createToken('test')->plainTextToken)
        ->getJson('/api/v1/menu/categories')
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('blocks cashier from inventory ingredient management', function (): void {
    $cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->withToken($cashier->createToken('test')->plainTextToken)
        ->postJson('/api/v1/inventory/ingredients', [
            'branch_id' => $this->branch->id,
            'name' => 'Tomatoes',
            'unit' => 'kg',
            'current_stock' => 10,
        ])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});
