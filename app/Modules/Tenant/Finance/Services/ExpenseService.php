<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Services;

use App\Models\User;
use App\Modules\Tenant\Finance\Models\Expense;
use App\Modules\Tenant\Finance\Models\ExpenseCategory;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExpenseService
{
    public function __construct(
        private readonly CashMovementService $cashMovements,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Expense
    {
        Branch::query()->findOrFail($data['branch_id']);

        $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        if (! $category->is_active) {
            throw new InvalidArgumentException('Expense category is inactive.');
        }

        $shift = isset($data['staff_shift_id'])
            ? StaffShift::query()->findOrFail($data['staff_shift_id'])
            : null;

        if ($shift && (int) $shift->branch_id !== (int) $data['branch_id']) {
            throw new InvalidArgumentException('The selected shift belongs to another branch.');
        }

        if ($shift && ! in_array($user->role, ['owner', 'manager'], true) && $shift->user_id !== $user->id) {
            throw new InvalidArgumentException('Staff may only submit cash expenses for their own shift.');
        }

        if ($data['payment_method'] === 'cash' && (! $shift || ! $shift->isActive())) {
            throw new InvalidArgumentException('Cash expenses require an active shift.');
        }

        $expense = Expense::create([
            ...$data,
            'created_by' => $user->id,
            'status' => 'pending',
        ]);

        AuditLogger::log('expense.created', $expense, [
            'amount' => $expense->amount,
            'payment_method' => $expense->payment_method,
        ]);

        return $this->load($expense);
    }

    public function approve(Expense $expense, User $user): Expense
    {
        if ($expense->status !== 'pending') {
            throw new InvalidArgumentException('Only pending expenses can be approved.');
        }

        return DB::transaction(function () use ($expense, $user): Expense {
            $movement = null;

            if ($expense->payment_method === 'cash') {
                $shift = $expense->shift;
                if (! $shift || ! $shift->isActive()) {
                    throw new InvalidArgumentException('The linked shift must be active to approve a cash expense.');
                }

                $movement = $this->cashMovements->create(
                    shift: $shift,
                    user: $user,
                    type: 'paid_out',
                    amount: (float) $expense->amount,
                    reason: 'Expense: '.$expense->description,
                    reference: $expense->reference ?: 'EXP-'.$expense->id,
                );
            }

            $expense->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
                'cash_movement_id' => $movement?->id,
            ]);

            AuditLogger::log('expense.approved', $expense, [
                'cash_movement_id' => $movement?->id,
            ]);

            return $this->load($expense->fresh());
        });
    }

    public function void(Expense $expense, User $user, string $reason): Expense
    {
        if ($expense->status === 'voided') {
            throw new InvalidArgumentException('Expense has already been voided.');
        }

        return DB::transaction(function () use ($expense, $user, $reason): Expense {
            if ($expense->cashMovement) {
                $this->cashMovements->reverse(
                    $expense->cashMovement,
                    $user,
                    'Expense voided: '.$reason,
                );
            }

            $expense->update([
                'status' => 'voided',
                'voided_by' => $user->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            AuditLogger::log('expense.voided', $expense, ['reason' => $reason]);

            return $this->load($expense->fresh());
        });
    }

    private function load(Expense $expense): Expense
    {
        return $expense->load([
            'branch:id,name',
            'category:id,name,code',
            'shift:id,user_id,clock_in,clock_out',
            'cashMovement',
            'creator:id,name',
            'approver:id,name',
        ]);
    }
}
