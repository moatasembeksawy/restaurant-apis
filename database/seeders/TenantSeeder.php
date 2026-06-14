<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // ── Create demo tenant ─────────────────────────────────────────────────
        $tenant = Tenant::firstOrCreate(
            ['subdomain' => 'nile'],
            [
                'name' => 'مطعم النيل',
                'locale' => 'ar',
                'plan' => 'pro',
                'status' => 'active',
                'kitchen_device_secret' => Hash::make('kitchen-secret-2024'),
                'feature_flags' => [],
            ],
        );

        // ── Create default branch ──────────────────────────────────────────────
        $branch = Branch::firstOrCreate(
            ['tenant_id' => $tenant->id, 'is_default' => true],
            [
                'name' => 'Cairo Branch',
                'name_ar' => 'فرع القاهرة',
                'address' => '5 Tahrir Square, Cairo',
                'phone' => '+20222345678',
                'timezone' => 'Africa/Cairo',
                'is_active' => true,
            ],
        );

        // ── Create staff users ─────────────────────────────────────────────────
        $owner = User::firstOrCreate(
            ['email' => 'owner@nile.eg'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => 'أحمد النيل',
                'phone' => '01001234567',
                'password' => Hash::make('password'),
                'role' => 'owner',
                'is_active' => true,
            ],
        );

        User::firstOrCreate(
            ['email' => 'manager@nile.eg'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => 'سارة محمد',
                'phone' => '01009876543',
                'password' => Hash::make('password'),
                'role' => 'manager',
                'is_active' => true,
            ],
        );

        User::firstOrCreate(
            ['email' => 'waiter@nile.eg'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => 'خالد سامي',
                'phone' => '01112345678',
                'password' => Hash::make('password'),
                'pin' => Hash::make('1234'),
                'role' => 'waiter',
                'is_active' => true,
            ],
        );

        User::firstOrCreate(
            ['email' => 'cashier@nile.eg'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => 'نور علي',
                'phone' => '01234567890',
                'password' => Hash::make('password'),
                'pin' => Hash::make('5678'),
                'role' => 'cashier',
                'is_active' => true,
            ],
        );

        User::firstOrCreate(
            ['email' => 'cook@nile.eg'],
            [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => 'عمر الطباخ',
                'phone' => '01556789012',
                'password' => Hash::make('password'),
                'pin' => Hash::make('9012'),
                'role' => 'cook',
                'is_active' => true,
            ],
        );

        // ── Seed menu categories ───────────────────────────────────────────────
        $this->seedMenu($tenant, $branch);

        // ── Seed floor tables ──────────────────────────────────────────────────
        $this->seedTables($tenant, $branch);

        // ── Assign Spatie roles ────────────────────────────────────────────────
        User::query()
            ->where('tenant_id', $tenant->id)
            ->each(fn (User $user) => $user->syncRoles([$user->role]));

        $this->command->info("Demo tenant '{$tenant->name}' seeded successfully.");
        $this->command->table(
            ['Credential', 'Value'],
            [
                ['Owner email', 'owner@nile.eg'],
                ['Password (all users)', 'password'],
                ['Waiter PIN', '1234'],
                ['Cashier PIN', '5678'],
                ['Cook PIN', '9012'],
                ['Subdomain', 'nile.localhost'],
            ],
        );
    }

    private function seedMenu(Tenant $tenant, Branch $branch): void
    {
        app()->instance('tenant', $tenant);

        $categories = [
            ['name_ar' => 'المشويات', 'name_en' => 'Grills', 'sort_order' => 1],
            ['name_ar' => 'السلطات', 'name_en' => 'Salads', 'sort_order' => 2],
            ['name_ar' => 'المشروبات', 'name_en' => 'Beverages', 'sort_order' => 3],
            ['name_ar' => 'الحلويات', 'name_en' => 'Desserts', 'sort_order' => 4],
        ];

        foreach ($categories as $catData) {
            $category = MenuCategory::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name_ar' => $catData['name_ar']],
                [...$catData, 'is_visible' => true],
            );

            $this->seedItemsForCategory($tenant, $category);
        }
    }

    private function seedItemsForCategory(Tenant $tenant, MenuCategory $category): void
    {
        $itemsByCat = [
            'المشويات' => [
                ['name_ar' => 'كوارع', 'name_en' => 'Veal Trotters', 'price' => 85.00, 'cost_price' => 42.00, 'prep_time' => 25],
                ['name_ar' => 'كباب مشكل', 'name_en' => 'Mixed Kebab', 'price' => 120.00, 'cost_price' => 55.00, 'prep_time' => 20],
                ['name_ar' => 'فراخ مشوية', 'name_en' => 'Grilled Chicken', 'price' => 95.00, 'cost_price' => 40.00, 'prep_time' => 20],
            ],
            'السلطات' => [
                ['name_ar' => 'سلطة خضراء', 'name_en' => 'Green Salad', 'price' => 25.00, 'cost_price' => 8.00, 'prep_time' => 5],
                ['name_ar' => 'تبولة', 'name_en' => 'Tabbouleh', 'price' => 30.00, 'cost_price' => 10.00, 'prep_time' => 5],
                ['name_ar' => 'بابا غنوج', 'name_en' => 'Baba Ghanoush', 'price' => 35.00, 'cost_price' => 12.00, 'prep_time' => 5],
            ],
            'المشروبات' => [
                ['name_ar' => 'عصير مانجو', 'name_en' => 'Mango Juice', 'price' => 30.00, 'cost_price' => 10.00, 'prep_time' => 3],
                ['name_ar' => 'شاي بالنعناع', 'name_en' => 'Mint Tea', 'price' => 15.00, 'cost_price' => 3.00, 'prep_time' => 5],
                ['name_ar' => 'قهوة عربي', 'name_en' => 'Arabic Coffee', 'price' => 20.00, 'cost_price' => 5.00, 'prep_time' => 5],
            ],
            'الحلويات' => [
                ['name_ar' => 'أم علي', 'name_en' => 'Om Ali', 'price' => 45.00, 'cost_price' => 15.00, 'prep_time' => 10],
                ['name_ar' => 'كنافة', 'name_en' => 'Kunafa', 'price' => 40.00, 'cost_price' => 12.00, 'prep_time' => 8],
            ],
        ];

        $items = $itemsByCat[$category->name_ar] ?? [];

        foreach ($items as $i => $itemData) {
            MenuItem::firstOrCreate(
                ['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name_ar' => $itemData['name_ar']],
                [
                    'name_en' => $itemData['name_en'],
                    'price' => $itemData['price'],
                    'cost_price' => $itemData['cost_price'],
                    'is_available' => true,
                    'preparation_time' => $itemData['prep_time'],
                    'sort_order' => $i + 1,
                ],
            );
        }
    }

    private function seedTables(Tenant $tenant, Branch $branch): void
    {
        app()->instance('tenant', $tenant);

        $tables = [
            ['name' => 'T1', 'section' => 'الصالة الرئيسية', 'capacity' => 4, 'position_x' => 1, 'position_y' => 1],
            ['name' => 'T2', 'section' => 'الصالة الرئيسية', 'capacity' => 4, 'position_x' => 2, 'position_y' => 1],
            ['name' => 'T3', 'section' => 'الصالة الرئيسية', 'capacity' => 6, 'position_x' => 3, 'position_y' => 1],
            ['name' => 'T4', 'section' => 'الصالة الرئيسية', 'capacity' => 2, 'position_x' => 1, 'position_y' => 2],
            ['name' => 'T5', 'section' => 'الصالة الرئيسية', 'capacity' => 2, 'position_x' => 2, 'position_y' => 2],
            ['name' => 'VIP1', 'section' => 'VIP', 'capacity' => 8, 'position_x' => 1, 'position_y' => 3],
            ['name' => 'VIP2', 'section' => 'VIP', 'capacity' => 10, 'position_x' => 2, 'position_y' => 3],
            ['name' => 'T-Outside', 'section' => 'الحديقة', 'capacity' => 4, 'position_x' => 1, 'position_y' => 4],
        ];

        foreach ($tables as $tableData) {
            FloorTable::firstOrCreate(
                ['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => $tableData['name']],
                [...$tableData, 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'free', 'is_active' => true],
            );
        }
    }
}
