<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\Customers\Models\CustomerAddress;
use App\Modules\Tenant\Districts\Models\District;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'growth', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->manager = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->district = District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'الدقي',
        'delivery_fee' => 15,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->manager->createToken('test')->plainTextToken;
});

it('adds multiple addresses to a customer', function (): void {
    $work = District::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'مدينة نصر',
        'delivery_fee' => 25,
    ]);

    $this->withToken($this->token)
        ->postJson("/api/v1/customers/{$this->customer->id}/addresses", [
            'district_id' => $this->district->id,
            'label' => 'المنزل',
            'address' => '١٢ شارع التحرير',
            'is_default' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.label', 'المنزل')
        ->assertJsonPath('data.delivery_fee', 15);

    $this->withToken($this->token)
        ->postJson("/api/v1/customers/{$this->customer->id}/addresses", [
            'district_id' => $work->id,
            'label' => 'العمل',
            'address' => '٤٤ عباس العقاد',
        ])
        ->assertCreated();

    $this->withToken($this->token)
        ->getJson("/api/v1/customers/{$this->customer->id}/addresses")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($this->customer->fresh()->default_address)->toBe('١٢ شارع التحرير');
});

it('includes addresses and district fees on the customer payload', function (): void {
    CustomerAddress::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'district_id' => $this->district->id,
        'address' => '١٢ شارع التحرير',
        'is_default' => true,
    ]);

    $this->withToken($this->token)
        ->getJson("/api/v1/customers/{$this->customer->id}")
        ->assertOk()
        ->assertJsonPath('data.addresses.0.address', '١٢ شارع التحرير')
        ->assertJsonPath('data.addresses.0.delivery_fee', 15);
});

it('promotes a new default address', function (): void {
    $home = CustomerAddress::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'district_id' => $this->district->id,
        'address' => 'Home',
        'is_default' => true,
    ]);
    $work = CustomerAddress::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'district_id' => $this->district->id,
        'address' => 'Work',
        'is_default' => false,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/customers/{$this->customer->id}/addresses/{$work->id}", [
            'is_default' => true,
        ])
        ->assertOk();

    expect($home->fresh()->is_default)->toBeFalse();
    expect($work->fresh()->is_default)->toBeTrue();
    expect($this->customer->fresh()->default_address)->toBe('Work');
});

it('does not let a customer use another customer address', function (): void {
    $other = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $address = CustomerAddress::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $other->id,
        'district_id' => $this->district->id,
    ]);

    $this->withToken($this->token)
        ->patchJson("/api/v1/customers/{$this->customer->id}/addresses/{$address->id}", [
            'label' => 'stolen',
        ])
        ->assertNotFound();
});
