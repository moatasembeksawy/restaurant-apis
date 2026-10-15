<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('POS_HUB_ENABLED', false),
    'token' => env('POS_HUB_TOKEN'),
    'tenant_id' => env('POS_HUB_TENANT_ID'),
    'branch_id' => env('POS_HUB_BRANCH_ID'),
    'port' => (int) env('POS_HUB_PORT', 7744),
    'host' => env('POS_HUB_HOST', '0.0.0.0'),
    'path' => env('POS_HUB_PATH', storage_path('app/pos-hub')),
    'cloud_url' => env('POS_HUB_CLOUD_URL'),
    'cloud_token' => env('POS_HUB_CLOUD_TOKEN'),
    'cloud_subdomain' => env('POS_HUB_CLOUD_SUBDOMAIN'),
    'discovery_host' => env('POS_HUB_DISCOVERY_HOST', 'pos-hub.local'),
];
