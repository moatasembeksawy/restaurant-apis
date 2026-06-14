<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        setPermissionsTeamId(null);

        $permissions = [
            // Tables
            'tables.view', 'tables.create', 'tables.update', 'tables.delete',

            // Menu
            'menu.view', 'menu.create', 'menu.update', 'menu.delete',

            // Orders
            'orders.view', 'orders.create', 'orders.update', 'orders.cancel',

            // Kitchen
            'kitchen.view', 'kitchen.update',

            // Payments
            'payments.process', 'payments.discount', 'payments.refund',

            // Reports
            'reports.view', 'reports.export',

            // Audit
            'audit.view',

            // Inventory (Phase 3)
            'inventory.view', 'inventory.manage', 'suppliers.manage',

            // Staff (Phase 3)
            'staff.view', 'staff.manage',

            // Users
            'users.view', 'users.manage',

            // Branches
            'branches.view', 'branches.manage',

            // Subscription
            'subscription.view', 'subscription.manage', 'subscription.cancel',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ── Roles and their permissions ────────────────────────────────────────

        Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web'])
            ->syncPermissions(Permission::all());

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web'])
            ->syncPermissions([
                'tables.view', 'tables.create', 'tables.update',
                'menu.view', 'menu.create', 'menu.update',
                'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
                'kitchen.view', 'kitchen.update',
                'payments.process', 'payments.discount', 'payments.refund',
                'reports.view', 'reports.export',
                'audit.view',
                'inventory.view', 'inventory.manage',
                'staff.view', 'staff.manage',
                'users.view',
                'subscription.view', 'subscription.manage',
                'suppliers.manage',
            ]);

        Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web'])
            ->syncPermissions([
                'tables.view',
                'menu.view',
                'orders.view', 'orders.create', 'orders.update',
                'payments.process', 'payments.discount',
                'reports.view',
            ]);

        Role::firstOrCreate(['name' => 'waiter', 'guard_name' => 'web'])
            ->syncPermissions([
                'tables.view', 'tables.update',
                'menu.view',
                'orders.view', 'orders.create', 'orders.update',
            ]);

        Role::firstOrCreate(['name' => 'cook', 'guard_name' => 'web'])
            ->syncPermissions([
                'kitchen.view', 'kitchen.update',
                'orders.view',
            ]);

        Role::firstOrCreate(['name' => 'rider', 'guard_name' => 'web'])
            ->syncPermissions([]);

        $this->command->info('Roles and permissions seeded.');
    }
}
