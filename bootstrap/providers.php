<?php

declare(strict_types=1);

return [
    // Core
    App\Providers\AppServiceProvider::class,

    // Module Service Providers
    App\Modules\Tenant\Providers\TenantServiceProvider::class,
    App\Modules\Auth\Providers\AuthServiceProvider::class,
    App\Modules\POS\Providers\POSServiceProvider::class,
    App\Modules\Delivery\Providers\DeliveryServiceProvider::class,
    App\Modules\Inventory\Providers\InventoryServiceProvider::class,
    App\Modules\Intelligence\Providers\IntelligenceServiceProvider::class,
    App\Modules\Platform\Providers\PlatformServiceProvider::class,
];
