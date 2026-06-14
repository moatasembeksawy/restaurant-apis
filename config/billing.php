<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans (EGP — monthly)
    |--------------------------------------------------------------------------
    |
    | Prices are in Egyptian Pounds. Paymob/Fawry amounts are sent in piastres
    | (amount × 100) as amount_cents.
    |
    */

    'currency' => 'EGP',

    'grace_period_days' => (int) env('BILLING_GRACE_PERIOD_DAYS', 7),

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'name_ar' => 'أساسي',
            'monthly_egp' => 499,
            'rank' => 1,
        ],
        'growth' => [
            'name' => 'Growth',
            'name_ar' => 'نمو',
            'monthly_egp' => 999,
            'rank' => 2,
        ],
        'pro' => [
            'name' => 'Pro',
            'name_ar' => 'احترافي',
            'monthly_egp' => 1999,
            'rank' => 3,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'name_ar' => 'مؤسسات',
            'monthly_egp' => 4999,
            'rank' => 4,
        ],
    ],

];
