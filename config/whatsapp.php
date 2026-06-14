<?php

declare(strict_types=1);

return [

    'api_version' => env('WHATSAPP_API_VERSION', 'v19.0'),

    'templates' => [
        'order_confirmed' => env('WA_TEMPLATE_ORDER_CONFIRMED', 'order_confirmed'),
        'order_ready' => env('WA_TEMPLATE_ORDER_READY', 'order_ready'),
        'receipt' => env('WA_TEMPLATE_RECEIPT', 'payment_receipt'),
        'eta_failure' => env('WA_TEMPLATE_ETA_FAILURE', 'eta_invoice_failed'),
        'billing_trial_expired' => env('WA_TEMPLATE_BILLING_TRIAL_EXPIRED', 'billing_trial_expired'),
        'billing_grace_expired' => env('WA_TEMPLATE_BILLING_GRACE_EXPIRED', 'billing_grace_expired'),
        'billing_payment_failed' => env('WA_TEMPLATE_BILLING_PAYMENT_FAILED', 'billing_payment_failed'),
        'billing_renewal_reminder' => env('WA_TEMPLATE_BILLING_RENEWAL_REMINDER', 'billing_renewal_reminder'),
        'billing_subscription_expired' => env('WA_TEMPLATE_BILLING_SUBSCRIPTION_EXPIRED', 'billing_subscription_expired'),
    ],

    'language' => env('WA_TEMPLATE_LANGUAGE', 'ar'),

];
