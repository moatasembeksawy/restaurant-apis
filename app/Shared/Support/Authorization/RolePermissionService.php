<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePermissionService
{
    public function syncUserRole(User $user): void
    {
        if (! $user->role || ! $user->tenant_id) {
            return;
        }

        $role = Role::query()
            ->where('name', $user->role)
            ->where('guard_name', SystemRoles::GUARD)
            ->where(function ($query) use ($user): void {
                $query->where('tenant_id', $user->tenant_id)
                    ->orWhereNull('tenant_id');
            })
            ->get()
            ->sortBy(fn (Role $candidate): int => $candidate->getAttribute('tenant_id') === null ? 1 : 0)
            ->first();

        if (! $role instanceof Role) {
            return;
        }

        setPermissionsTeamId($user->tenant_id);
        $user->syncRoles([$role]);
    }
}
