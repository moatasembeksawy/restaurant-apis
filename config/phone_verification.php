<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Phone verification (OTP)
    |--------------------------------------------------------------------------
    |
    | Disabled until a provider is configured. While disabled, the phone
    | verification and phone password-reset endpoints respond with 503.
    |
    | Drivers: "log" (local development), "twilio", "vonage", "smsmisr", "whatsapp".
    | Provider credentials live in config/services.php.
    |
    */

    'enabled' => (bool) env('PHONE_VERIFICATION_ENABLED', false),

    'driver' => env('PHONE_VERIFICATION_DRIVER', 'log'),

    'code_length' => (int) env('PHONE_VERIFICATION_CODE_LENGTH', 6),

    'ttl_minutes' => (int) env('PHONE_VERIFICATION_TTL_MINUTES', 10),

    'max_attempts' => (int) env('PHONE_VERIFICATION_MAX_ATTEMPTS', 5),

    'resend_cooldown_seconds' => (int) env('PHONE_VERIFICATION_RESEND_COOLDOWN', 60),

];
