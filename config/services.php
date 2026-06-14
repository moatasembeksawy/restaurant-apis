<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ── Egyptian Tax Authority (ETA) ──────────────────────────────────────────
    'eta' => [
        'client_id' => env('ETA_CLIENT_ID'),
        'client_secret' => env('ETA_CLIENT_SECRET'),
        'portal_url' => env('ETA_PORTAL_URL', 'https://api.invoicing.eta.gov.eg'),
        'token_url' => env('ETA_TOKEN_URL', 'https://id.eta.gov.eg/connect/token'),
        'cert_path' => env('ETA_CERT_PATH', storage_path('certs')),
    ],

    // ── WhatsApp Business API (Meta) ──────────────────────────────────────────
    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v19.0'),
    ],

    // ── Paymob ────────────────────────────────────────────────────────────────
    'paymob' => [
        'api_key' => env('PAYMOB_API_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),
    ],

    // ── Fawry ─────────────────────────────────────────────────────────────────
    'fawry' => [
        'merchant_code' => env('FAWRY_MERCHANT_CODE'),
        'security_key' => env('FAWRY_SECURITY_KEY'),
        'base_url' => env('FAWRY_BASE_URL', 'https://www.atfawry.com/ECommerceWeb/Fawry'),
    ],

];
