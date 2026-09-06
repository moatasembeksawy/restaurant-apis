<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->useMemoryCacheIfRedisIsDown();

        $this->call([
            RolesAndPermissionsSeeder::class,
            PlatformAdminSeeder::class,
            TenantSeeder::class,
        ]);
    }

    /**
     * CACHE_STORE=redis must not abort seeding when Redis is not running.
     * Spatie writes the permission cache on every Role/Permission mutation.
     */
    private function useMemoryCacheIfRedisIsDown(): void
    {
        if (config('cache.default') !== 'redis') {
            return;
        }

        try {
            app('redis')->connection((string) config('cache.stores.redis.connection', 'cache'))->ping();
        } catch (Throwable) {
            config([
                'cache.default' => 'array',
                'permission.cache.store' => 'array',
            ]);
            app()->forgetInstance('cache');
            app()->forgetInstance('cache.store');
            app()->forgetInstance(PermissionRegistrar::class);
        }
    }
}
