<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');

    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $category = MenuCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->item = MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('uploads a menu item photo', function (): void {
    $file = UploadedFile::fake()->image('koshari.jpg');

    $response = $this->withToken($this->token)
        ->post("/api/v1/menu/items/{$this->item->id}/photo", [
            'photo' => $file,
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect($response->json('data.photo_url'))->not->toBeNull();
    expect($this->item->fresh()->getMedia('photo'))->toHaveCount(1);
});

it('deletes a menu item photo', function (): void {
    $file = UploadedFile::fake()->image('koshari.jpg');

    $this->withToken($this->token)
        ->post("/api/v1/menu/items/{$this->item->id}/photo", ['photo' => $file], ['Accept' => 'application/json'])
        ->assertOk();

    $this->withToken($this->token)
        ->deleteJson("/api/v1/menu/items/{$this->item->id}/photo")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);

    expect($this->item->fresh()->getMedia('photo'))->toHaveCount(0);
});
