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

        setPermissionsTeamId(null);

        if (! Role::query()->where('name', $user->role)->where('guard_name', 'web')->exists()) {
            return;
        }

        setPermissionsTeamId($user->tenant_id);
        $user->syncRoles([$user->role]);
    }
}
