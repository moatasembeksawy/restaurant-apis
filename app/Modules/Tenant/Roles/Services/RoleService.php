<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Roles\Services;

use App\Models\User;
use App\Shared\Support\Audit\AuditLogger;
use App\Shared\Support\Authorization\RolePermissionService;
use App\Shared\Support\Authorization\SystemRoles;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoleService
{
    public function __construct(private readonly RolePermissionService $rolePermissions) {}

    /** @return EloquentCollection<int, Permission> */
    public function permissions(): EloquentCollection
    {
        return Permission::query()
            ->where('guard_name', SystemRoles::GUARD)
            ->orderBy('name')
            ->get();
    }

    public function findPermission(int $id): Permission
    {
        $permission = Permission::query()
            ->where('guard_name', SystemRoles::GUARD)
            ->find($id);

        if (! $permission instanceof Permission) {
            throw new NotFoundHttpException;
        }

        return $permission;
    }

    /** @return Collection<int, Role> */
    public function list(): Collection
    {
        return Role::query()
            ->with('permissions')
            ->where('guard_name', SystemRoles::GUARD)
            ->where(function ($query): void {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $this->tenantId());
            })
            ->orderBy('name')
            ->get()
            ->groupBy('name')
            ->map(fn (Collection $group) => $group
                ->sortBy(fn (Role $role): int => $this->tenantKey($role) === null ? 1 : 0)
                ->first())
            ->filter()
            ->sortBy('name')
            ->values();
    }

    public function find(int $id): Role
    {
        return $this->locate($id)->load('permissions');
    }

    /**
     * @param  array{name: string, permissions?: list<string>}  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $this->assertNameAvailable($data['name']);
            $permissions = $data['permissions'] ?? [];
            $this->assertKnownPermissions($permissions);

            try {
                $role = Role::query()->create([
                    'name' => $data['name'],
                    'guard_name' => SystemRoles::GUARD,
                    'tenant_id' => $this->tenantId(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new InvalidArgumentException('A role with this name already exists.');
            }

            $role->syncPermissions($permissions);

            AuditLogger::log('role.created', $role, [
                'name' => $role->name,
                'permissions' => $permissions,
            ]);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array{name?: string, permissions?: list<string>}  $data
     */
    public function update(int $id, array $data): Role
    {
        return DB::transaction(function () use ($id, $data): Role {
            $role = $this->locate($id);

            if (array_key_exists('name', $data) && $data['name'] !== $role->name) {
                $this->rename($role, $data['name']);
                $role = $role->fresh() ?? $role;
            }

            if (array_key_exists('permissions', $data)) {
                $role = $this->replacePermissions($role, $data['permissions']);
            }

            return $role->load('permissions');
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $role = $this->locate($id);

            if ($this->tenantKey($role) === null || $role->name === 'owner') {
                throw new InvalidArgumentException('System roles cannot be deleted.');
            }

            $name = $role->name;
            $resetsSystemRole = SystemRoles::isSystem($name);

            if (! $resetsSystemRole && User::query()->where('role', $name)->exists()) {
                throw new InvalidArgumentException('Reassign staff before deleting this role.');
            }

            $role->delete();

            if ($resetsSystemRole) {
                $this->reassignUsers($name);
            }

            AuditLogger::log('role.deleted', null, ['name' => $name]);
        });
    }

    /** @param  list<string>  $permissions */
    public function syncPermissions(int $id, array $permissions): Role
    {
        return DB::transaction(function () use ($id, $permissions): Role {
            $role = $this->replacePermissions($this->locate($id), $permissions);

            return $role->load('permissions');
        });
    }

    /** @param  list<string>  $permissions */
    public function attachPermissions(int $id, array $permissions): Role
    {
        return DB::transaction(function () use ($id, $permissions): Role {
            $this->assertKnownPermissions($permissions);
            $role = $this->editable($this->locate($id));
            $role->givePermissionTo($permissions);
            $this->reassignUsers($role->name);

            AuditLogger::log('role.permissions_attached', $role, [
                'permissions' => $permissions,
            ]);

            return $role->load('permissions');
        });
    }

    public function detachPermission(int $roleId, int $permissionId): Role
    {
        return DB::transaction(function () use ($roleId, $permissionId): Role {
            $permission = $this->findPermission($permissionId);
            $role = $this->editable($this->locate($roleId));
            $role->revokePermissionTo($permission);
            $this->reassignUsers($role->name);

            AuditLogger::log('role.permission_detached', $role, [
                'permission' => $permission->name,
            ]);

            return $role->load('permissions');
        });
    }

    /** @param  list<string>  $permissions */
    private function replacePermissions(Role $role, array $permissions): Role
    {
        $this->assertKnownPermissions($permissions);
        $role = $this->editable($role);
        $role->syncPermissions($permissions);
        $this->reassignUsers($role->name);

        AuditLogger::log('role.permissions_synced', $role, [
            'permissions' => $permissions,
        ]);

        return $role;
    }

    private function rename(Role $role, string $name): void
    {
        if ($this->tenantKey($role) === null || SystemRoles::isSystem($role->name)) {
            throw new InvalidArgumentException('System roles cannot be renamed.');
        }

        $this->assertNameAvailable($name);
        $previous = $role->name;
        $role->name = $name;
        $role->save();

        User::query()
            ->where('role', $previous)
            ->orderBy('id')
            ->each(function (User $user) use ($name): void {
                $user->update(['role' => $name]);
            });

        AuditLogger::log('role.renamed', $role, [
            'from' => $previous,
            'to' => $name,
        ]);
    }

    private function editable(Role $role): Role
    {
        if ($role->name === 'owner') {
            throw new InvalidArgumentException('The owner role cannot be changed.');
        }

        if ($this->tenantKey($role) !== null) {
            return $role;
        }

        return $this->createOverride($role);
    }

    private function createOverride(Role $system): Role
    {
        $existing = $this->findOverride($system->name);

        if ($existing instanceof Role) {
            return $existing;
        }

        try {
            $override = Role::query()->create([
                'name' => $system->name,
                'guard_name' => SystemRoles::GUARD,
                'tenant_id' => $this->tenantId(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->findOverride($system->name);

            if (! $existing instanceof Role) {
                throw new InvalidArgumentException('Unable to customize this role.');
            }

            return $existing;
        }

        $override->syncPermissions(
            $system->loadMissing('permissions')->permissions->pluck('name')->all(),
        );

        return $override;
    }

    private function locate(int $id): Role
    {
        $role = Role::query()
            ->where('guard_name', SystemRoles::GUARD)
            ->find($id);

        if (! $role instanceof Role || ! $this->visibleToTenant($role)) {
            throw new NotFoundHttpException;
        }

        if ($this->tenantKey($role) === null) {
            return $this->findOverride($role->name) ?? $role;
        }

        return $role;
    }

    private function findOverride(string $name): ?Role
    {
        $role = Role::query()
            ->where('name', $name)
            ->where('guard_name', SystemRoles::GUARD)
            ->where('tenant_id', $this->tenantId())
            ->first();

        return $role instanceof Role ? $role : null;
    }

    private function visibleToTenant(Role $role): bool
    {
        $tenantId = $this->tenantKey($role);

        return $tenantId === null || $tenantId === $this->tenantId();
    }

    private function assertNameAvailable(string $name): void
    {
        if (preg_match(SystemRoles::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Role names must use lowercase letters, numbers, and underscores.');
        }

        if (SystemRoles::isSystem($name)) {
            throw new InvalidArgumentException('This role name is reserved.');
        }

        $exists = Role::query()
            ->where('name', $name)
            ->where('guard_name', SystemRoles::GUARD)
            ->where('tenant_id', $this->tenantId())
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('A role with this name already exists.');
        }
    }

    /** @param  list<string>  $names */
    private function assertKnownPermissions(array $names): void
    {
        if ($names === []) {
            return;
        }

        $known = Permission::query()
            ->where('guard_name', SystemRoles::GUARD)
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        $missing = array_values(array_diff($names, $known));

        if ($missing !== []) {
            throw new InvalidArgumentException('Unknown permissions: '.implode(', ', $missing));
        }
    }

    private function reassignUsers(string $roleName): void
    {
        User::query()
            ->where('role', $roleName)
            ->orderBy('id')
            ->each(function (User $user): void {
                $this->rolePermissions->syncUserRole($user);
            });
    }

    private function tenantId(): int
    {
        return (int) app('tenant')->id;
    }

    private function tenantKey(Role $role): ?int
    {
        $tenantId = $role->getAttribute('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }
}
