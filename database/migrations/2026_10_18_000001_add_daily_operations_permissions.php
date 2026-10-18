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

        $permissions = [];

        foreach ($this->permissionNames() as $name) {
            $permissions[$name] = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $this->give('owner', $permissions, array_keys($permissions));
        $this->give('manager', $permissions, [
            'printing.view', 'printing.manage', 'printing.execute',
            'business_days.view', 'business_days.manage',
            'shifts.view', 'shifts.operate',
            'cash_movements.view', 'cash_movements.create', 'cash_movements.reverse',
            'expenses.view', 'expenses.create', 'expenses.approve', 'expense_categories.manage',
        ]);
        $this->give('cashier', $permissions, [
            'printing.view', 'printing.execute',
            'business_days.view',
            'shifts.view', 'shifts.operate',
            'cash_movements.view', 'cash_movements.create',
            'expenses.view', 'expenses.create',
        ]);
        $this->give('waiter', $permissions, [
            'printing.view', 'printing.execute',
            'shifts.view', 'shifts.operate',
        ]);
        $this->give('cook', $permissions, [
            'printing.view', 'printing.execute',
            'shifts.view', 'shifts.operate',
        ]);

        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        setPermissionsTeamId(null);

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $this->permissionNames())
            ->delete();

        $this->forgetPermissionCache();
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return [
            'printing.view', 'printing.manage', 'printing.execute',
            'business_days.view', 'business_days.manage',
            'shifts.view', 'shifts.operate',
            'cash_movements.view', 'cash_movements.create', 'cash_movements.reverse',
            'expenses.view', 'expenses.create', 'expenses.approve', 'expense_categories.manage',
        ];
    }

    /**
     * @param  array<string, Permission>  $permissions
     * @param  list<string>  $names
     */
    private function give(string $role, array $permissions, array $names): void
    {
        $model = Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->first();

        if (! $model) {
            return;
        }

        $model->givePermissionTo(array_map(
            fn (string $name): Permission => $permissions[$name],
            $names,
        ));
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
