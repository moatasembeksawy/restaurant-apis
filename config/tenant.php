<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Base Domain
    |--------------------------------------------------------------------------
    |
    | Tenant subdomains are resolved as {subdomain}.{base_domain}.
    | Example: nile.restoapp.eg when base_domain is restoapp.eg
    |
    */

    'base_domain' => env('TENANT_BASE_DOMAIN', 'restoapp.eg'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolution Header
    |--------------------------------------------------------------------------
    |
    | API clients without subdomain DNS can pass the tenant subdomain in this
    | header (e.g. mobile apps hitting api.restoapp.eg directly).
    |
    */

    'subdomain_header' => env('TENANT_SUBDOMAIN_HEADER', 'X-Tenant-Subdomain'),

];
