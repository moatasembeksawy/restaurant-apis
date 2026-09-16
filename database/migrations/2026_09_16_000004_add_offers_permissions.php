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

        $view = Permission::firstOrCreate(['name' => 'offers.view', 'guard_name' => 'web']);
        $manage = Permission::firstOrCreate(['name' => 'offers.manage', 'guard_name' => 'web']);

        $this->givePermissions('owner', [$view, $manage]);
        $this->givePermissions('manager', [$view, $manage]);
        $this->givePermissions('cashier', [$view]);
        $this->givePermissions('waiter', [$view]);

        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        setPermissionsTeamId(null);

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', ['offers.view', 'offers.manage'])
            ->delete();

        $this->forgetPermissionCache();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function givePermissions(string $role, array $permissions): void
    {
        $model = Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->first();

        $model?->givePermissionTo($permissions);
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
