<?php

declare(strict_types=1);

return [

    'api_version' => env('WHATSAPP_API_VERSION', 'v19.0'),

    'templates' => [
        'order_confirmed' => env('WA_TEMPLATE_ORDER_CONFIRMED', 'order_confirmed'),
        'order_ready' => env('WA_TEMPLATE_ORDER_READY', 'order_ready'),
        'receipt' => env('WA_TEMPLATE_RECEIPT', 'payment_receipt'),
        'eta_failure' => env('WA_TEMPLATE_ETA_FAILURE', 'eta_invoice_failed'),
    ],

    'language' => env('WA_TEMPLATE_LANGUAGE', 'ar'),

];
