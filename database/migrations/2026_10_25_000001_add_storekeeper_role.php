<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        setPermissionsTeamId(null);

        $role = Role::query()->firstOrCreate([
            'name' => 'storekeeper',
            'guard_name' => 'web',
        ]);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissionNames())
            ->get();

        $role->syncPermissions($permissions);

        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        setPermissionsTeamId(null);

        Role::query()
            ->where('name', 'storekeeper')
            ->where('guard_name', 'web')
            ->whereNull('tenant_id')
            ->delete();

        $this->forgetPermissionCache();
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return [
            'inventory.view', 'inventory.manage',
            'suppliers.manage',
            'branches.view',
            'shifts.view', 'shifts.operate',
        ];
    }

    private function forgetPermissionCache(): void
    {
        try {
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
        } catch (Throwable) {
            // Cache backend unavailable (typical: Redis not started).
        }
    }
};
