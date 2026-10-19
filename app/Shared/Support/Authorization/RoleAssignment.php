<?php

declare(strict_types=1);

namespace App\Shared\Support\Authorization;

use App\Models\User;
use App\Modules\Tenant\Models\Tenant;
use Spatie\Permission\Models\Role;

final class RoleAssignment
{
    public static function currentTenantId(): ?int
    {
        if (app()->bound('tenant')) {
            $tenant = app('tenant');

            if ($tenant instanceof Tenant) {
                return (int) $tenant->id;
            }
        }

        $user = auth()->user();

        return $user instanceof User ? (int) $user->tenant_id : null;
    }

    public static function isAssignable(string $role, int $tenantId): bool
    {
        if ($role === 'owner' || preg_match(SystemRoles::NAME_PATTERN, $role) !== 1) {
            return false;
        }

        if (in_array($role, SystemRoles::ASSIGNABLE, true)) {
            return true;
        }

        return Role::query()
            ->where('name', $role)
            ->where('guard_name', SystemRoles::GUARD)
            ->where('tenant_id', $tenantId)
            ->exists();
    }
}
