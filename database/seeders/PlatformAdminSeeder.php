<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Models\PlatformAdmin;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('platform.admin.email', 'admin@restoapp.eg');
        $password = env('PLATFORM_ADMIN_PASSWORD', 'password');

        PlatformAdmin::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) config('platform.admin.name', 'Platform Admin'),
                'password' => $password,
                'is_active' => true,
            ],
        );
    }
}
