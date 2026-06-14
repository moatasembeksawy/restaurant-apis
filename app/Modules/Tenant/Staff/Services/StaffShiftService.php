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

        return $query->get()->map(fn (StaffShift $shift) => $this->format($shift));
    }

    public function clockIn(User $user, ?int $branchId = null, ?string $notes = null): array
    {
        if ($this->activeShiftForUser($user->id)) {
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
        ]);

        AuditLogger::log('staff_shift.clock_in', $shift, [
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $this->format($shift->load(['user:id,name,role', 'branch:id,name']));
    }

    public function clockOut(User $user, ?string $notes = null): array
    {
        $shift = $this->activeShiftForUser($user->id);

        if (! $shift) {
            throw new InvalidArgumentException('No active shift found for this staff member.');
        }

        $shift->update([
            'clock_out' => now(),
            'notes' => $notes ?? $shift->notes,
        ]);

        AuditLogger::log('staff_shift.clock_out', $shift, [
            'user_id' => $user->id,
            'duration_minutes' => $shift->clock_in->diffInMinutes($shift->clock_out),
        ]);

        return $this->format($shift->fresh(['user:id,name,role', 'branch:id,name']));
    }

    private function activeShiftForUser(int $userId): ?StaffShift
    {
        return StaffShift::query()
            ->where('user_id', $userId)
            ->whereNull('clock_out')
            ->first();
    }

    /** @return array<string, mixed> */
    private function format(StaffShift $shift): array
    {
        return [
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
        ];
    }
}
