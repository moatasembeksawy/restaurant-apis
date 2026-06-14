<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
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

it('lists menu categories for the tenant only', function (): void {
    MenuCategory::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);
    MenuCategory::factory()->count(2)->create(); // other tenant

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/menu/categories')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

it('creates a menu item with Arabic name', function (): void {
    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->withToken($this->token)
        ->postJson('/api/v1/menu/items', [
            'category_id' => $category->id,
            'name_ar' => 'كوشري مصري',
            'name_en' => 'Egyptian Koshari',
            'price' => 35.00,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name_ar', 'كوشري مصري')
        ->assertJsonPath('data.price', '35.00');
});

it('toggles item availability', function (): void {
    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $item = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/menu/items/{$item->id}/toggle")
        ->assertOk();

    expect($item->fresh()->is_available)->toBeFalse();
});

it('prevents access to another tenant menu items', function (): void {
    $otherCategory = MenuCategory::factory()->create();
    $otherItem = MenuItem::factory()->create(['tenant_id' => $otherCategory->tenant_id, 'category_id' => $otherCategory->id]);

    $this->withToken($this->token)
        ->getJson("/api/v1/menu/items/{$otherItem->id}")
        ->assertNotFound();
});
