<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');

    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'owner',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->owner->createToken('test')->plainTextToken;
});

it('uploads a tenant logo', function (): void {
    $file = UploadedFile::fake()->image('logo.png');

    $response = $this->withToken($this->token)
        ->post('/api/v1/settings/logo', ['logo' => $file], ['Accept' => 'application/json'])
        ->assertOk();

    expect($response->json('data.logo_url'))->not->toBeNull();
    expect($this->tenant->fresh()->logo_url)->not->toBeNull();
    expect($this->tenant->fresh()->getMedia('logo'))->toHaveCount(1);

    $this->withToken($this->token)
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('data.logo_url', $response->json('data.logo_url'));
});

it('replaces an existing tenant logo', function (): void {
    $this->withToken($this->token)
        ->post('/api/v1/settings/logo', [
            'logo' => UploadedFile::fake()->image('old.png'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $this->withToken($this->token)
        ->post('/api/v1/settings/logo', [
            'logo' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect($this->tenant->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('deletes a tenant logo', function (): void {
    $this->withToken($this->token)
        ->post('/api/v1/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $this->withToken($this->token)
        ->deleteJson('/api/v1/settings/logo')
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    expect($this->tenant->fresh()->logo_url)->toBeNull();
    expect($this->tenant->fresh()->getMedia('logo'))->toHaveCount(0);
});

it('rejects a non-image logo', function (): void {
    $this->withToken($this->token)
        ->post('/api/v1/settings/logo', [
            'logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'logo')
        ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
});

it('forbids managers from uploading a logo', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($manager->createToken('test')->plainTextToken)
        ->post('/api/v1/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});

it('shows the tenant logo on the public qr menu', function (): void {
    $category = MenuCategory::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_visible' => true,
    ]);
    MenuItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);
    $table = FloorTable::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'status' => 'free',
    ]);

    $uploaded = $this->withToken($this->token)
        ->post('/api/v1/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $this->getJson("/api/v1/qr/{$table->qr_token}/menu")
        ->assertOk()
        ->assertJsonPath('data.restaurant.logo_url', $uploaded->json('data.logo_url'));
});
