<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Loyalty Program
    |--------------------------------------------------------------------------
    |
    | points_per_egp — customers earn 1 point per X EGP spent (e.g. 10 → 100 EGP = 10 pts)
    | redeem_value_egp — each point is worth this many EGP when redeemed
    |
    */

    'loyalty' => [
        'points_per_egp' => (float) env('LOYALTY_POINTS_PER_EGP', 10),
        'redeem_value_egp' => (float) env('LOYALTY_REDEEM_VALUE_EGP', 0.25),
        'min_redeem_points' => (int) env('LOYALTY_MIN_REDEEM_POINTS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregator Commission Estimates
    |--------------------------------------------------------------------------
    |
    | Used for net-revenue comparison — actual rates vary by contract.
    |
    */

    'aggregators' => [
        'talabat' => [
            'label_ar' => 'طلبات',
            'commission_pct' => (float) env('AGGREGATOR_TALABAT_COMMISSION', 25),
        ],
        'elmenus' => [
            'label_ar' => 'إلمينيو',
            'commission_pct' => (float) env('AGGREGATOR_ELMENUS_COMMISSION', 20),
        ],
    ],

    'own_channels' => ['dine_in', 'qr', 'whatsapp', 'own_delivery'],

];
