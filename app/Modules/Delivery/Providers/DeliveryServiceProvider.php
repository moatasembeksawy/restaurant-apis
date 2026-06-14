<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Providers;

use App\Modules\Delivery\Aggregators\Services\AggregatorOrderService;
use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\Delivery\QRMenu\Services\QRMenuService;
use App\Modules\Delivery\Riders\Services\DeliveryService;
use App\Modules\Delivery\WhatsApp\Services\WhatsAppNotificationService;
use App\Modules\Delivery\WhatsApp\Services\WhatsAppOrderService;
use Illuminate\Support\ServiceProvider;

class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerService::class);
        $this->app->singleton(QRMenuService::class);
        $this->app->singleton(DeliveryService::class);
        $this->app->singleton(WhatsAppOrderService::class);
        $this->app->singleton(WhatsAppNotificationService::class);
        $this->app->singleton(AggregatorOrderService::class);
    }

    public function boot(): void {}
}
