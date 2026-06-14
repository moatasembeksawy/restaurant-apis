<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Staff\Models\StaffShift;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    app()->instance('tenant', $this->tenant);
    $this->token = $this->cashier->createToken('test')->plainTextToken;
});

it('clocks staff in and out', function (): void {
    $clockIn = $this->withToken($this->token)
        ->postJson('/api/v1/staff/shifts/clock-in', ['notes' => 'Morning shift'])
        ->assertCreated()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.user_id', $this->cashier->id);

    expect(StaffShift::query()->whereNull('clock_out')->count())->toBe(1);

    $this->withToken($this->token)
        ->postJson('/api/v1/staff/shifts/clock-out')
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(StaffShift::query()->whereNull('clock_out')->count())->toBe(0);
    expect($clockIn->json('data.id'))->toBe(
        StaffShift::query()->where('user_id', $this->cashier->id)->value('id'),
    );
});

it('lists active shifts for the branch', function (): void {
    StaffShift::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'clock_in' => now()->subHour(),
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/staff/shifts/active')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.user_id'))->toBe($this->cashier->id);
});

it('rejects double clock-in', function (): void {
    $this->withToken($this->token)
        ->postJson('/api/v1/staff/shifts/clock-in')
        ->assertCreated();

    $this->withToken($this->token)
        ->postJson('/api/v1/staff/shifts/clock-in')
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.code', 'SHIFT_CLOCK_IN_FAILED');
});

it('blocks staff shifts on starter plan', function (): void {
    $starter = Tenant::factory()->create(['plan' => 'starter', 'status' => 'active']);
    app()->instance('tenant', $starter);

    $user = User::factory()->create([
        'tenant_id' => $starter->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $starter->id])->id,
        'role' => 'cashier',
        'is_active' => true,
    ]);

    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/v1/staff/shifts/clock-in')
        ->assertPaymentRequired()
        ->assertJsonPath('errors.0.code', 'FEATURE_NOT_AVAILABLE');
});
