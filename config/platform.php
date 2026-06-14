<?php

declare(strict_types=1);

return [

    'admin' => [
        'email' => env('PLATFORM_ADMIN_EMAIL', 'admin@restoapp.eg'),
        'name' => env('PLATFORM_ADMIN_NAME', 'Platform Admin'),
    ],

    'impersonation_ttl_minutes' => (int) env('PLATFORM_IMPERSONATION_TTL_MINUTES', 60),

];
