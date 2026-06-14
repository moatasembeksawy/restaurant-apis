<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
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

it('shows tenant settings for owner', function (): void {
    $this->tenant->update(['whatsapp_phone_number_id' => '12345']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/settings')
        ->assertOk();

    expect($response->json('data.name'))->toBe($this->tenant->name);
    expect($response->json('data.whatsapp_phone_number_id'))->toBe('12345');
    expect($response->json('data.subscription.plan'))->toBe('growth');
});

it('updates tenant settings', function (): void {
    $response = $this->withToken($this->token)
        ->patchJson('/api/v1/settings', [
            'name' => 'Updated Restaurant',
            'locale' => 'en',
            'whatsapp_phone_number_id' => '999888',
            'talabat_webhook_secret' => 'secret-talabat',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Restaurant')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.has_talabat_webhook_secret', true);

    expect($this->tenant->fresh()->name)->toBe('Updated Restaurant');
});

it('rejects duplicate custom domains', function (): void {
    Tenant::factory()->create([
        'subdomain' => 'other',
        'custom_domain' => 'menu.taken.com',
    ]);

    $this->withToken($this->token)
        ->patchJson('/api/v1/settings', [
            'custom_domain' => 'menu.taken.com',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'SETTINGS_UPDATE_FAILED');
});

it('forbids managers from updating settings', function (): void {
    $manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->withToken($manager->createToken('test')->plainTextToken)
        ->patchJson('/api/v1/settings', ['locale' => 'en'])
        ->assertForbidden()
        ->assertJsonPath('errors.0.code', 'FORBIDDEN');
});
