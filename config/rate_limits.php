<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Per-tenant API rate limits (requests per minute)
    |--------------------------------------------------------------------------
    */

    'default_per_minute' => (int) env('API_RATE_LIMIT_DEFAULT', 60),

    'plans' => [
        'starter' => (int) env('API_RATE_LIMIT_STARTER', 60),
        'growth' => (int) env('API_RATE_LIMIT_GROWTH', 120),
        'pro' => (int) env('API_RATE_LIMIT_PRO', 240),
        'enterprise' => (int) env('API_RATE_LIMIT_ENTERPRISE', 600),
    ],

];
