<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
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

function staffPayload(array $overrides = []): array
{
    return [
        'name' => 'Waiter',
        'role' => 'waiter',
        'branch_id' => test()->branch->id,
        'pin' => '4321',
        ...$overrides,
    ];
}

it('stores staff phones normalized', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/staff', staffPayload(['phone' => '010 0123 4567']))
        ->assertCreated()
        ->assertJsonPath('data.phone', '+201001234567')
        ->assertJsonPath('data.phone_verified_at', null);
});

it('rejects invalid or landline staff phones', function (string $phone): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/staff', staffPayload(['phone' => $phone]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'phone');
})->with(['not-a-phone', '0100123', '0222345678']);

it('rejects a phone already used by any user, in any format or tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $otherTenant->id, 'phone' => '+201001234567']);

    $this->withToken($this->token)
        ->postJson('/api/v1/staff', staffPayload(['phone' => '01001234567']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.message', 'This phone number is already registered.');
});

it('lets a staff member keep their own phone on update', function (): void {
    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'phone' => '01001234567',
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/staff/{$staff->id}", ['phone' => '+201001234567', 'name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');
});

it('clears phone verification when the phone changes', function (): void {
    $staff = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'waiter',
        'phone' => '01001234567',
    ]);
    $staff->markPhoneAsVerified();

    $staff->update(['phone' => '01111111111']);

    expect($staff->fresh()->phone_verified_at)->toBeNull();
});

it('rejects an invalid owner phone on registration', function (): void {
    $this->postJson('/api/v1/onboarding/register', [
        'restaurant_name' => 'Cafe',
        'owner_name' => 'Owner',
        'owner_email' => 'new-owner@test.eg',
        'owner_password' => 'password123',
        'owner_phone' => '12',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'owner_phone');
});
