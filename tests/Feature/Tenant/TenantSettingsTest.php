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
    expect($response->json('data.qr_menu_token'))->toBe($this->branch->fresh()->qr_menu_token);
    expect($response->json('data.qr_menu_url'))->toEndWith("/api/v1/qr/{$this->branch->qr_menu_token}/menu");
});

it('uses the tenant locale when no supported language header is provided', function (): void {
    $this->tenant->update(['locale' => 'ar']);

    expect($this->owner->preferredLocale())->toBe('ar');

    $this->withToken($this->token)
        ->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('meta.message', 'حسنًا');
});

it('lets the language header override the tenant locale', function (): void {
    $this->tenant->update(['locale' => 'ar']);

    $this->withToken($this->token)
        ->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('meta.message', 'OK');
});

it('shows tenant settings and logo for cashier', function (): void {
    $this->tenant->update(['logo_url' => 'https://cdn.example.com/logo.png']);
    $cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->withToken($cashier->createToken('test')->plainTextToken)
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('data.name', $this->tenant->name)
        ->assertJsonPath('data.logo_url', 'https://cdn.example.com/logo.png');
});

it('updates tenant settings', function (): void {
    $response = $this->withToken($this->token)
        ->patchJson('/api/v1/settings', [
            'name' => 'Updated Restaurant',
            'description' => 'A riverside kitchen.',
            'locale' => 'en',
            'whatsapp_phone_number_id' => '999888',
            'talabat_webhook_secret' => 'secret-talabat',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Restaurant')
        ->assertJsonPath('data.description', 'A riverside kitchen.')
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.has_talabat_webhook_secret', true);

    expect($this->tenant->fresh()->name)->toBe('Updated Restaurant');
    expect($this->tenant->fresh()->description)->toBe('A riverside kitchen.');
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

it('updates tenant tax and service charge settings', function (): void {
    $this->withToken($this->token)
        ->patchJson('/api/v1/settings', [
            'tax_rate' => 14,
            'tax_rate_applies_to' => ['dine_in', 'takeaway'],
            'service_charge_rate' => 12,
            'service_charge_applies_to' => ['dine_in'],
        ])
        ->assertOk()
        ->assertJsonPath('data.tax_rate', 14)
        ->assertJsonPath('data.tax_rate_applies_to', ['dine_in', 'takeaway'])
        ->assertJsonPath('data.service_charge_rate', 12)
        ->assertJsonPath('data.service_charge_applies_to', ['dine_in']);

    expect((float) $this->tenant->fresh()->tax_rate)->toBe(14.0);
    expect((float) $this->tenant->fresh()->service_charge_rate)->toBe(12.0);
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
