<?php

declare(strict_types=1);
use App\Modules\Auth\Providers\AuthServiceProvider;
use App\Modules\Delivery\Providers\DeliveryServiceProvider;
use App\Modules\Intelligence\Providers\IntelligenceServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\POS\Providers\POSServiceProvider;
use App\Modules\Tenant\Providers\TenantServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    // Core
    AppServiceProvider::class,
    TelescopeServiceProvider::class,

    // Module Service Providers
    TenantServiceProvider::class,
    AuthServiceProvider::class,
    POSServiceProvider::class,
    DeliveryServiceProvider::class,
    InventoryServiceProvider::class,
    IntelligenceServiceProvider::class,
    PlatformServiceProvider::class,
];
