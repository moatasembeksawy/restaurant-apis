<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Staff\Services;

use App\Models\User;
use App\Modules\Tenant\Staff\Models\StaffShift;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StaffShiftService
{
    public function __construct(
        private readonly StaffShiftSalesService $sales,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function list(?int $branchId = null, ?int $userId = null, ?string $date = null): Collection
    {
        $query = StaffShift::query()
            ->with(['user:id,name,role', 'branch:id,name'])
            ->latest('clock_in');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($date) {
            $query->whereDate('clock_in', $date);
        }

        return $query->limit(100)->get()->map(fn (StaffShift $shift) => $this->format($shift));
    }

    /** @return Collection<int, array<string, mixed>> */
    public function active(?int $branchId = null): Collection
    {
        $query = StaffShift::query()
            ->with(['user:id,name,role', 'branch:id,name'])
            ->whereNull('clock_out')
            ->latest('clock_in');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get()->map(fn (StaffShift $shift) => $this->format($shift, includeSales: true));
    }

    public function show(StaffShift $shift): array
    {
        return $this->format($shift->load(['user:id,name,role', 'branch:id,name']), includeSales: true);
    }

    public function currentFor(User $user): ?array
    {
        $shift = $this->resolveActiveShift($user);

        return $shift ? $this->format($shift->load(['user:id,name,role', 'branch:id,name']), includeSales: true) : null;
    }

    public function resolveActiveShift(User $user): ?StaffShift
    {
        return StaffShift::query()
            ->where('user_id', $user->id)
            ->whereNull('clock_out')
            ->first();
    }

    public function requireActiveShiftForCashier(User $user): StaffShift
    {
        $shift = $this->resolveActiveShift($user);

        if (! $shift) {
            throw new InvalidArgumentException('You must clock in before processing payments.');
        }

        return $shift;
    }

    public function resolveShiftForPayment(User $cashier): ?StaffShift
    {
        if ($cashier->role === 'cashier') {
            return $this->requireActiveShiftForCashier($cashier);
        }

        return $this->resolveActiveShift($cashier);
    }

    public function clockIn(User $user, ?int $branchId = null, ?string $notes = null, ?float $openingFloat = null): array
    {
        if ($this->resolveActiveShift($user)) {
            throw new InvalidArgumentException('Staff member already has an active shift.');
        }

        $branchId = $branchId ?? $user->branch_id;

        if (! $branchId) {
            throw new InvalidArgumentException('branch_id is required.');
        }

        $shift = StaffShift::create([
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'clock_in' => now(),
            'notes' => $notes,
            'opening_float' => $openingFloat ?? 0,
        ]);

        AuditLogger::log('staff_shift.clock_in', $shift, [
            'user_id' => $user->id,
            'branch_id' => $branchId,
            'opening_float' => $shift->opening_float,
        ]);

        return $this->format($shift->load(['user:id,name,role', 'branch:id,name']), includeSales: true);
    }

    public function clockOut(User $user, ?string $notes = null, ?float $closingCashCount = null): array
    {
        $shift = $this->resolveActiveShift($user);

        if (! $shift) {
            throw new InvalidArgumentException('No active shift found for this staff member.');
        }

        $expectedCash = $this->sales->expectedCashInDrawer($shift);
        $variance = $closingCashCount !== null
            ? round($closingCashCount - $expectedCash, 2)
            : null;

        $shift->update([
            'clock_out' => now(),
            'notes' => $notes ?? $shift->notes,
            'closing_cash_count' => $closingCashCount,
            'expected_cash' => $closingCashCount !== null ? $expectedCash : null,
            'cash_variance' => $variance,
        ]);

        AuditLogger::log('staff_shift.clock_out', $shift, [
            'user_id' => $user->id,
            'duration_minutes' => $shift->clock_in->diffInMinutes($shift->clock_out),
            'expected_cash' => $expectedCash,
            'closing_cash_count' => $closingCashCount,
            'cash_variance' => $variance,
        ]);

        return $this->format($shift->fresh(['user:id,name,role', 'branch:id,name']), includeSales: true);
    }

    /** @return array<string, mixed> */
    private function format(StaffShift $shift, bool $includeSales = false): array
    {
        $payload = [
            'id' => $shift->id,
            'user_id' => $shift->user_id,
            'user_name' => $shift->user?->name,
            'user_role' => $shift->user?->role,
            'branch_id' => $shift->branch_id,
            'branch_name' => $shift->branch?->name,
            'clock_in' => $shift->clock_in?->toIso8601String(),
            'clock_out' => $shift->clock_out?->toIso8601String(),
            'is_active' => $shift->isActive(),
            'duration_minutes' => $shift->clock_out
                ? $shift->clock_in->diffInMinutes($shift->clock_out)
                : $shift->clock_in->diffInMinutes(now()),
            'notes' => $shift->notes,
            'opening_float' => (string) $shift->opening_float,
            'closing_cash_count' => $shift->closing_cash_count !== null
                ? (string) $shift->closing_cash_count
                : null,
            'expected_cash' => $shift->expected_cash !== null
                ? (string) $shift->expected_cash
                : null,
            'cash_variance' => $shift->cash_variance !== null
                ? (string) $shift->cash_variance
                : null,
        ];

        if ($includeSales) {
            $payload['sales'] = $this->sales->summarize($shift);
        }

        return $payload;
    }
}
