<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Finance\Services;

use App\Models\User;
use App\Modules\Tenant\Finance\Models\CashMovement;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CashMovementService
{
    public function create(
        StaffShift $shift,
        User $user,
        string $type,
        float $amount,
        string $reason,
        ?string $reference = null,
    ): CashMovement {
        if (! $shift->isActive()) {
            throw new InvalidArgumentException('Cash movements can only be added to an active shift.');
        }

        if (! in_array($type, array_diff(CashMovement::TYPES, ['reversal']), true)) {
            throw new InvalidArgumentException('Invalid cash movement type.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Cash movement amount must be greater than zero.');
        }

        if (! in_array($user->role, ['owner', 'manager'], true) && $shift->user_id !== $user->id) {
            throw new InvalidArgumentException('Staff may only add movements to their own active shift.');
        }

        $movement = CashMovement::create([
            'branch_id' => $shift->branch_id,
            'staff_shift_id' => $shift->id,
            'created_by' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'reference' => $reference,
            'occurred_at' => now(),
        ]);

        AuditLogger::log('cash_movement.created', $movement, [
            'staff_shift_id' => $shift->id,
            'type' => $type,
            'amount' => $movement->amount,
        ]);

        return $movement->load('creator:id,name');
    }

    public function reverse(CashMovement $movement, User $user, string $reason): CashMovement
    {
        if ($movement->reversed_at !== null || $movement->type === 'reversal') {
            throw new InvalidArgumentException('Cash movement has already been reversed.');
        }

        if (! $movement->shift()->whereNull('clock_out')->exists()) {
            throw new InvalidArgumentException('Movements on a closed shift cannot be reversed.');
        }

        return DB::transaction(function () use ($movement, $user, $reason): CashMovement {
            $movement->update([
                'reversed_at' => now(),
                'reversed_by' => $user->id,
            ]);

            $reversal = CashMovement::create([
                'branch_id' => $movement->branch_id,
                'staff_shift_id' => $movement->staff_shift_id,
                'created_by' => $user->id,
                'reversal_of_id' => $movement->id,
                'type' => 'reversal',
                'amount' => $movement->amount,
                'reason' => $reason,
                'reference' => $movement->reference,
                'occurred_at' => now(),
            ]);

            AuditLogger::log('cash_movement.reversed', $movement, [
                'reversal_id' => $reversal->id,
                'reason' => $reason,
            ]);

            return $reversal->load('creator:id,name');
        });
    }

    /** @return array{paid_in: float, paid_out: float, safe_drop: float, float_adjustment: float, net: float} */
    public function totalsForShift(StaffShift $shift): array
    {
        $totals = CashMovement::query()
            ->where('staff_shift_id', $shift->id)
            ->whereNull('reversed_at')
            ->where('type', '!=', 'reversal')
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $paidIn = round((float) ($totals['paid_in'] ?? 0), 2);
        $paidOut = round((float) ($totals['paid_out'] ?? 0), 2);
        $safeDrop = round((float) ($totals['safe_drop'] ?? 0), 2);
        $floatAdjustment = round((float) ($totals['float_adjustment'] ?? 0), 2);

        return [
            'paid_in' => $paidIn,
            'paid_out' => $paidOut,
            'safe_drop' => $safeDrop,
            'float_adjustment' => $floatAdjustment,
            'net' => round($paidIn + $floatAdjustment - $paidOut - $safeDrop, 2),
        ];
    }
}
