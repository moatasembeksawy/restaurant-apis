<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Modules\Tenant\Finance\Models\Expense;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['plan' => 'pro', 'status' => 'active']);
    $this->branch = Branch::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_default' => true,
    ]);
    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role' => 'cashier',
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

it('includes paid in paid out and safe drops in shift reconciliation', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $shiftId = $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 100])
        ->assertCreated()
        ->json('data.id');

    foreach ([
        ['type' => 'paid_in', 'amount' => 50, 'reason' => 'Added change'],
        ['type' => 'paid_out', 'amount' => 20, 'reason' => 'Petty cash'],
        ['type' => 'safe_drop', 'amount' => 10, 'reason' => 'Moved to safe'],
    ] as $movement) {
        $this->postJson("/api/v1/staff/shifts/{$shiftId}/cash-movements", $movement)
            ->assertCreated();
    }

    $this->getJson('/api/v1/staff/shifts/current')
        ->assertOk()
        ->assertJsonPath('data.sales.cash_movements.paid_in', 50)
        ->assertJsonPath('data.sales.cash_movements.paid_out', 20)
        ->assertJsonPath('data.sales.cash_movements.safe_drop', 10)
        ->assertJsonPath('data.sales.expected_cash_in_drawer', 120)
        ->assertJsonCount(3, 'data.sales.transactions');
});

it('reverses a movement without deleting its audit history', function (): void {
    Sanctum::actingAs($this->cashier, ['*'], 'sanctum');

    $shiftId = $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 100])
        ->assertCreated()
        ->json('data.id');

    $movementId = $this->postJson("/api/v1/staff/shifts/{$shiftId}/cash-movements", [
        'type' => 'paid_out',
        'amount' => 25,
        'reason' => 'Incorrect movement',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $this->postJson("/api/v1/cash-movements/{$movementId}/reverse", [
        'reason' => 'Entered by mistake',
    ])->assertCreated()
        ->assertJsonPath('data.type', 'reversal')
        ->assertJsonPath('data.reversal_of_id', $movementId);

    $this->getJson("/api/v1/staff/shifts/{$shiftId}")
        ->assertOk()
        ->assertJsonPath('data.sales.expected_cash_in_drawer', 100);

    expect(CashMovement::query()->count())->toBe(2);
    expect(CashMovement::query()->find($movementId)->reversed_at)->not->toBeNull();
});

it('turns an approved cash expense into a shift paid out', function (): void {
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $shiftId = $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 100])
        ->assertCreated()
        ->json('data.id');

    $categoryId = $this->postJson('/api/v1/expense-categories', [
        'name' => 'Utilities',
        'code' => 'utilities',
    ])->assertCreated()->json('data.id');

    $expenseId = $this->postJson('/api/v1/expenses', [
        'branch_id' => $this->branch->id,
        'expense_category_id' => $categoryId,
        'staff_shift_id' => $shiftId,
        'amount' => 30,
        'payment_method' => 'cash',
        'description' => 'Kitchen gas refill',
        'expense_date' => now()->toDateString(),
    ])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->json('data.id');

    $this->postJson("/api/v1/expenses/{$expenseId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.cash_movement.type', 'paid_out');

    $this->getJson("/api/v1/staff/shifts/{$shiftId}")
        ->assertOk()
        ->assertJsonPath('data.sales.cash_movements.paid_out', 30)
        ->assertJsonPath('data.sales.expected_cash_in_drawer', 70);

    expect(Expense::query()->find($expenseId)->cash_movement_id)->not->toBeNull();
});

it('voids a cash expense by reversing its shift movement', function (): void {
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $shiftId = $this->postJson('/api/v1/staff/shifts/clock-in', ['opening_float' => 100])
        ->assertCreated()
        ->json('data.id');
    $categoryId = $this->postJson('/api/v1/expense-categories', ['name' => 'Repairs'])
        ->assertCreated()
        ->json('data.id');
    $expenseId = $this->postJson('/api/v1/expenses', [
        'branch_id' => $this->branch->id,
        'expense_category_id' => $categoryId,
        'staff_shift_id' => $shiftId,
        'amount' => 40,
        'payment_method' => 'cash',
        'description' => 'Emergency repair',
        'expense_date' => now()->toDateString(),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/expenses/{$expenseId}/approve")->assertOk();
    $this->postJson("/api/v1/expenses/{$expenseId}/void", ['reason' => 'Duplicate invoice'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    $this->getJson("/api/v1/staff/shifts/{$shiftId}")
        ->assertOk()
        ->assertJsonPath('data.sales.expected_cash_in_drawer', 100);
});

it('approves non cash expenses without affecting a shift drawer', function (): void {
    Sanctum::actingAs($this->manager, ['*'], 'sanctum');

    $categoryId = $this->postJson('/api/v1/expense-categories', ['name' => 'Subscriptions'])
        ->assertCreated()
        ->json('data.id');

    $expenseId = $this->postJson('/api/v1/expenses', [
        'branch_id' => $this->branch->id,
        'expense_category_id' => $categoryId,
        'amount' => 80,
        'payment_method' => 'card',
        'description' => 'Software subscription',
        'expense_date' => now()->toDateString(),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/expenses/{$expenseId}/approve")
        ->assertOk()
        ->assertJsonPath('data.cash_movement_id', null);

    $this->getJson('/api/v1/expenses/summary')
        ->assertOk()
        ->assertJsonPath('data.expenses_count', 1)
        ->assertJsonPath('data.total_expenses', 80)
        ->assertJsonPath('data.by_category.0.category_name', 'Subscriptions')
        ->assertJsonPath('data.by_payment_method.card.total', 80);
});
