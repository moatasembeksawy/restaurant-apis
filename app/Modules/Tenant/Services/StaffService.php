<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Services;

use App\Models\User;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Subscription\Services\PlanLimitService;
use App\Shared\Support\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

class StaffService
{
    /** @var list<string> */
    private const STAFF_ROLES = ['manager', 'cashier', 'waiter', 'cook', 'rider'];

    public function __construct(private readonly PlanLimitService $planLimits) {}

    /** @return Collection<int, User> */
    public function list(?int $branchId = null): Collection
    {
        return User::query()
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->formatUser($user));
    }

    public function create(array $data, User $createdBy): array
    {
        $this->planLimits->check('users');

        $role = $data['role'];

        if ($role === 'owner') {
            throw new InvalidArgumentException('Cannot create additional owner accounts via staff API.');
        }

        if (! in_array($role, self::STAFF_ROLES, true)) {
            throw new InvalidArgumentException('Invalid staff role.');
        }

        $this->assertBranchBelongsToTenant((int) $data['branch_id']);

        if (in_array($role, ['manager'], true) && empty($data['email'])) {
            throw new InvalidArgumentException('Email is required for manager accounts.');
        }

        if (! empty($data['email']) && User::query()->withoutGlobalScopes()->where('email', $data['email'])->exists()) {
            throw new InvalidArgumentException('This email is already registered.');
        }

        if (in_array($role, ['waiter', 'cashier', 'cook', 'rider'], true) && empty($data['pin']) && empty($data['password'])) {
            throw new InvalidArgumentException('PIN or password is required for this role.');
        }

        $user = User::create([
            'tenant_id' => app('tenant')->id,
            'branch_id' => $data['branch_id'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'] ?? null,
            'pin' => isset($data['pin']) ? Hash::make((string) $data['pin']) : null,
            'role' => $role,
            'is_active' => true,
        ]);

        AuditLogger::log('staff.created', $user, [
            'role' => $role,
            'created_by' => $createdBy->id,
        ]);

        return $this->formatUser($user->fresh());
    }

    public function update(User $user, array $data, User $updatedBy): array
    {
        if ($user->role === 'owner' && $updatedBy->role !== 'owner') {
            throw new InvalidArgumentException('Only the owner can modify owner accounts.');
        }

        if (isset($data['role'])) {
            if ($data['role'] === 'owner') {
                throw new InvalidArgumentException('Cannot promote staff to owner.');
            }

            if (! in_array($data['role'], self::STAFF_ROLES, true)) {
                throw new InvalidArgumentException('Invalid staff role.');
            }
        }

        if (isset($data['branch_id'])) {
            $this->assertBranchBelongsToTenant((int) $data['branch_id']);
        }

        if (! empty($data['email']) && User::query()
            ->withoutGlobalScopes()
            ->where('email', $data['email'])
            ->where('id', '!=', $user->id)
            ->exists()) {
            throw new InvalidArgumentException('This email is already registered.');
        }

        $updates = array_filter([
            'name' => $data['name'] ?? null,
            'email' => array_key_exists('email', $data) ? $data['email'] : null,
            'phone' => $data['phone'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'role' => $data['role'] ?? null,
            'is_active' => $data['is_active'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($data['is_active'])) {
            $updates['is_active'] = $data['is_active'];

            if ($data['is_active'] === false) {
                $updates['deactivation_reason'] = 'manual';
            } else {
                $updates['deactivation_reason'] = null;
            }
        }

        if (isset($data['password'])) {
            $updates['password'] = $data['password'];
        }

        if (isset($data['pin'])) {
            $updates['pin'] = Hash::make((string) $data['pin']);
        }

        $user->update($updates);

        AuditLogger::log('staff.updated', $user, [
            'updated_by' => $updatedBy->id,
            'changes' => array_keys($updates),
        ]);

        return $this->formatUser($user->fresh());
    }

    public function deactivate(User $user, User $deactivatedBy): array
    {
        if ($user->role === 'owner') {
            throw new InvalidArgumentException('Cannot deactivate the owner account.');
        }

        if ($user->id === $deactivatedBy->id) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        $user->update([
            'is_active' => false,
            'deactivation_reason' => 'manual',
        ]);
        $user->tokens()->delete();

        AuditLogger::log('staff.deactivated', $user, ['deactivated_by' => $deactivatedBy->id]);

        return $this->formatUser($user->fresh());
    }

    /** @return array<string, mixed> */
    public function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'branch_id' => $user->branch_id,
            'is_active' => $user->is_active,
            'has_pin' => $user->pin !== null,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }

    private function assertBranchBelongsToTenant(int $branchId): void
    {
        if (! Branch::query()->where('id', $branchId)->exists()) {
            throw new InvalidArgumentException('Invalid branch for this tenant.');
        }
    }
}
