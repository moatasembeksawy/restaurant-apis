<?php

declare(strict_types=1);

/**
 * Generates API.md and Postman collection/environment for all api/v1/* endpoints.
 *
 * Usage: php tools/generate-api-documentation.php
 */

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ── Plan features (mirrors Tenant::planIncludesFeature) ────────────────────────

const PLAN_FEATURES = [
    'starter' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice'],
    'growth' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice', 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders'],
    'pro' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice', 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders', 'inventory', 'recipe_costing', 'suppliers', 'staff_shifts', 'audit_log', 'waste_log'],
    'enterprise' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice', 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders', 'inventory', 'recipe_costing', 'suppliers', 'staff_shifts', 'audit_log', 'waste_log', 'multi_branch', 'ai_reports', 'loyalty', 'whatsapp_marketing', 'aggregator_analytics'],
];

const ERROR_CODES = [
    ['401', 'UNAUTHENTICATED', 'Missing or invalid Bearer token'],
    ['401', 'INVALID_CREDENTIALS', 'Email/password login failed'],
    ['401', 'INVALID_PIN', 'Device PIN login failed'],
    ['401', 'INVALID_SIGNATURE', 'Webhook signature verification failed'],
    ['403', 'FORBIDDEN', 'Insufficient role or permission'],
    ['403', 'ACCOUNT_SUSPENDED', 'Tenant subscription suspended'],
    ['404', 'NOT_FOUND', 'Resource or route not found'],
    ['404', 'TENANT_NOT_FOUND', 'Tenant could not be resolved from host or header'],
    ['402', 'FEATURE_NOT_AVAILABLE', 'Plan does not include required feature'],
    ['402', 'PLAN_LIMIT_EXCEEDED', 'Plan limit reached (branches, users, orders)'],
    ['422', 'VALIDATION_ERROR', 'Request validation failed (includes field)'],
    ['400', 'ERROR', 'Generic client error'],
    ['400', 'TENANT_REQUIRED', 'X-Tenant-Subdomain header required'],
];

const WEBHOOK_BODIES = [
    'api/v1/webhook/paymob' => ['type' => 'TRANSACTION', 'obj' => ['id' => 123456, 'success' => true, 'amount_cents' => 15000]],
    'api/v1/webhook/fawry' => ['merchantRefNumber' => 'ORD-001', 'paymentStatus' => 'PAID', 'paymentAmount' => 150.00],
    'api/v1/webhook/aggregators/talabat' => ['external_id' => 'TBL-998877', 'status' => 'new', 'items' => [['name' => 'كشري', 'quantity' => 2, 'price' => 45.00]]],
    'api/v1/webhook/aggregators/elmenus' => ['order_ref' => 'ELM-554433', 'status' => 'accepted', 'customer_phone' => '+201098765432'],
    'api/v1/webhook/whatsapp' => [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'messages' => [
                                ['from' => '201012345678', 'text' => ['body' => 'أريد طلب كشري']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

const DESCRIPTION_OVERRIDES = [
    'App\\Modules\\Tenant\\Http\\Controllers\\OnboardingController@register' => 'Self-service restaurant onboarding. Creates tenant, owner account, and default branch. Subdomain is optional — auto-generated when omitted.',
    'App\\Modules\\Auth\\Http\\Controllers\\AuthController@deviceLogin' => 'Branch-scoped PIN login for waiters and cashiers. Requires tenant resolution via header or subdomain host.',
    'App\\Modules\\Auth\\Http\\Controllers\\AuthController@kitchenLogin' => 'Kitchen display device login using a shared branch secret.',
    'App\\Modules\\Delivery\\QRMenu\\Http\\Controllers\\QRMenuController@show' => 'Public QR menu for a table token. No authentication required.',
    'App\\Modules\\Delivery\\QRMenu\\Http\\Controllers\\QRMenuController@placeOrder' => 'Place a dine-in or takeaway order from the public QR menu.',
    'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@store' => 'Create a new order with line items (dine-in, delivery, aggregator, etc.).',
    'App\\Modules\\POS\\Billing\\Http\\Controllers\\PaymentController@settle' => 'Settle payment for an open order (cash, card, Vodafone Cash, split, etc.).',
    'App\\Modules\\POS\\Billing\\Http\\Controllers\\PaymentController@refund' => 'Refund a settled order payment.',
    'App\\Modules\\Tenant\\Http\\Controllers\\TenantSettingsController@verifyDomain' => 'Verify custom domain DNS configuration for white-label access.',
    'App\\Modules\\Tenant\\Staff\\Http\\Controllers\\StaffShiftController@clockIn' => 'Clock in staff member for the current shift.',
    'App\\Modules\\Tenant\\Staff\\Http\\Controllers\\StaffShiftController@clockOut' => 'Clock out staff member and close the active shift.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@store' => 'Create a draft purchase order for a supplier.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@submit' => 'Submit purchase order to supplier for fulfillment.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@receive' => 'Receive goods against a submitted purchase order.',
    'App\\Modules\\Platform\\Http\\Controllers\\AdminTenantController@impersonate' => 'Issue a short-lived tenant token for platform admin impersonation.',
];

// ── Bootstrap helpers ────────────────────────────────────────────────────────

/** @return list<string> */
function findPhpFiles(string $baseDir, string $pathSegment): array
{
    $files = [];
    if (! is_dir($baseDir)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        if (str_contains($file->getPathname(), $pathSegment)) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

/** @return array<string, array<string, list<string>>> */
function loadFormRequestRules(string $basePath): array
{
    $rules = [];
    $files = findPhpFiles($basePath.'/app/Modules', '/Http/Requests/');

    foreach (array_unique($files) as $file) {
        $class = classFromFile($file, $basePath);
        if ($class === null || ! class_exists($class)) {
            continue;
        }

        try {
            $ref = new ReflectionClass($class);
            if (! $ref->isInstantiable() || ! $ref->hasMethod('rules')) {
                continue;
            }
            $instance = $ref->newInstanceWithoutConstructor();
            /** @var array<string, mixed> $classRules */
            $classRules = $instance->rules();

            if (in_array(
                App\Shared\Support\Http\Requests\Concerns\HasPaginationRules::class,
                $ref->getTraitNames(),
                true,
            ) && $ref->hasMethod('paginationRules')) {
                $method = $ref->getMethod('paginationRules');
                $method->setAccessible(true);
                /** @var array<string, list<string>> $pagination */
                $pagination = $method->invoke($instance);
                $classRules = array_merge($classRules, $pagination);
            }

            $rules[$class] = normalizeRules($classRules);
        } catch (Throwable) {
            continue;
        }
    }

    return $rules;
}

function classFromFile(string $file, string $basePath): ?string
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        return null;
    }

    if (! preg_match('/namespace\s+([^;]+);/', $contents, $ns) || ! preg_match('/class\s+(\w+)/', $contents, $cls)) {
        return null;
    }

    return trim($ns[1]).'\\'.trim($cls[1]);
}

/** @param array<string, mixed> $rules */
function normalizeRules(array $rules): array
{
    $normalized = [];
    foreach ($rules as $field => $ruleSet) {
        if (is_string($ruleSet)) {
            $normalized[$field] = explode('|', $ruleSet);
        } elseif (is_array($ruleSet)) {
            $normalized[$field] = array_map(
                static fn ($r) => is_object($r) ? (string) $r : (string) $r,
                $ruleSet,
            );
        }
    }

    return $normalized;
}

/** @return array<string, string|null> */
function mapControllerFormRequests(string $basePath): array
{
    $map = [];
    $files = findPhpFiles($basePath.'/app/Modules', '/Http/Controllers/');

    foreach ($files as $file) {
        $class = classFromFile($file, $basePath);
        if ($class === null || ! class_exists($class)) {
            continue;
        }

        try {
            $ref = new ReflectionClass($class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType();
                    if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                        continue;
                    }
                    $paramClass = $type->getName();
                    if (is_subclass_of($paramClass, Illuminate\Foundation\Http\FormRequest::class)) {
                        $map[$class.'@'.$method->getName()] = $paramClass;
                    }
                }
            }
        } catch (Throwable) {
            continue;
        }
    }

    return $map;
}

/** @return list<array<string, mixed>> */
function collectEndpoints(Illuminate\Routing\Router $router): array
{
    $endpoints = [];

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1')) {
            continue;
        }
        if (preg_match('#(horizon|telescope|docs)#', $uri)) {
            continue;
        }

        $action = $route->getActionName();
        if ($action === 'Closure') {
            continue;
        }

        [$controller, $method] = array_pad(explode('@', $action), 2, null);
        if ($method === null) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $methods = array_filter(
            $route->methods(),
            static fn (string $m) => ! in_array($m, ['HEAD'], true),
        );

        foreach ($methods as $httpMethod) {
            $endpoints[] = [
                'method' => $httpMethod,
                'path' => '/'.$uri,
                'uri' => $uri,
                'controller' => $controller,
                'action' => $method,
                'action_key' => $controller.'@'.$method,
                'middleware' => $middleware,
                'module' => resolveModule($uri, $controller),
                'auth' => resolveAuth($middleware),
                'permissions' => extractMiddlewareParams($middleware, 'permission'),
                'features' => extractMiddlewareParams($middleware, 'feature'),
                'path_params' => extractPathParams($uri),
            ];
        }
    }

    usort($endpoints, static function (array $a, array $b): int {
        return [$a['module'], $a['path'], $a['method']] <=> [$b['module'], $b['path'], $b['method']];
    });

    return $endpoints;
}

function resolveModule(string $uri, string $controller): string
{
    if (str_starts_with($uri, 'api/v1/admin/')) {
        return 'Platform';
    }
    if (str_starts_with($uri, 'api/v1/webhook/')) {
        return 'Webhooks';
    }
    if (str_starts_with($uri, 'api/v1/qr/')) {
        return 'Delivery · QR Menu';
    }
    if (str_contains($uri, 'onboarding')) {
        return 'Tenant · Onboarding';
    }

    return match (true) {
        str_contains($controller, '\\Auth\\') => 'Auth',
        str_contains($controller, '\\Platform\\') => 'Platform',
        str_contains($controller, '\\POS\\') => 'POS',
        str_contains($controller, '\\Delivery\\') => 'Delivery',
        str_contains($controller, '\\Inventory\\') => 'Inventory',
        str_contains($controller, '\\Intelligence\\') => 'Intelligence',
        str_contains($controller, '\\Tenant\\') => 'Tenant',
        default => 'General',
    };
}

/** @param list<string> $middleware */
function resolveAuth(array $middleware): array
{
    $joined = implode('|', $middleware);
    $hasSanctum = str_contains($joined, 'Authenticate:sanctum') || str_contains($joined, 'auth:sanctum');
    $hasTenant = str_contains($joined, 'TenantMiddleware') || str_contains($joined, 'tenant');
    $hasAdmin = str_contains($joined, 'EnsurePlatformAdmin') || str_contains($joined, 'platform.admin');
    $header = (string) config('tenant.subdomain_header', 'X-Tenant-Subdomain');

    if ($hasAdmin) {
        return ['label' => 'Admin Bearer token', 'type' => 'admin_bearer', 'headers' => ['Authorization' => 'Bearer {{admin_token}}']];
    }

    if ($hasSanctum && $hasTenant) {
        return [
            'label' => 'Bearer token + tenant header',
            'type' => 'bearer_tenant',
            'headers' => [
                'Authorization' => 'Bearer {{tenant_token}}',
                $header => '{{tenant_subdomain}}',
            ],
        ];
    }

    if ($hasTenant && ! $hasSanctum) {
        return [
            'label' => 'Tenant header only',
            'type' => 'tenant_header',
            'headers' => [$header => '{{tenant_subdomain}}'],
        ];
    }

    if ($hasSanctum) {
        return ['label' => 'Bearer token', 'type' => 'bearer', 'headers' => ['Authorization' => 'Bearer {{tenant_token}}']];
    }

    return ['label' => 'None', 'type' => 'none', 'headers' => []];
}

/** @param list<string> $middleware */
function extractMiddlewareParams(array $middleware, string $alias): array
{
    $values = [];
    foreach ($middleware as $entry) {
        if (str_starts_with($entry, $alias.':')) {
            $values[] = substr($entry, strlen($alias) + 1);
        } elseif (str_contains($entry, 'EnsurePermission:')) {
            if ($alias === 'permission') {
                $values[] = str_replace('App\\Shared\\Support\\Http\\Middleware\\EnsurePermission:', '', $entry);
            }
        } elseif (str_contains($entry, 'EnsurePlanFeature:')) {
            if ($alias === 'feature') {
                $values[] = str_replace('App\\Shared\\Support\\Http\\Middleware\\EnsurePlanFeature:', '', $entry);
            }
        }
    }

    return array_values(array_unique($values));
}

/** @return list<string> */
function extractPathParams(string $uri): array
{
    preg_match_all('/\{([^}]+)\}/', $uri, $matches);

    return $matches[1] ?? [];
}

function endpointDescription(array $endpoint): string
{
    $key = $endpoint['action_key'];
    if (isset(DESCRIPTION_OVERRIDES[$key])) {
        return DESCRIPTION_OVERRIDES[$key];
    }

    try {
        $ref = new ReflectionMethod($endpoint['controller'], $endpoint['action']);
        $doc = $ref->getDocComment() ?: '';
        if (preg_match('/@(?:summary|title)\s+(.+)/', $doc, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/\*\s+([^@\n*][^\n*]+)/', $doc, $m)) {
            $line = trim($m[1]);
            if ($line !== '' && ! str_starts_with($line, '@')) {
                return $line;
            }
        }
    } catch (Throwable) {
        // fall through
    }

    return humanize($endpoint['action']).' — '.trim(str_replace('api/v1/', '', $endpoint['uri']), '/');
}

function humanize(string $value): string
{
    $value = preg_replace('/([a-z])([A-Z])/', '$1 $2', $value) ?? $value;

    return ucfirst(str_replace('_', ' ', $value));
}

/** @param array<string, list<string>> $allRules */
function rulesForEndpoint(array $endpoint, array $requestMap, array $allRules): array
{
    $requestClass = $requestMap[$endpoint['action_key']] ?? null;
    if ($requestClass && isset($allRules[$requestClass])) {
        return partitionQueryAndBody($allRules[$requestClass], $endpoint['method']);
    }

    return ['query' => [], 'body' => []];
}

/** @param array<string, list<string>> $rules */
function partitionQueryAndBody(array $rules, string $method): array
{
    $query = [];
    $body = [];

    foreach ($rules as $field => $fieldRules) {
        if (str_contains($field, '*')) {
            $body[$field] = $fieldRules;

            continue;
        }

        if (in_array($method, ['GET', 'DELETE'], true)) {
            $query[$field] = $fieldRules;
        } else {
            $body[$field] = $fieldRules;
        }
    }

    return ['query' => $query, 'body' => $body];
}

/** @param array<string, list<string>> $rules */
function buildExamplePayload(array $rules, int $depth = 0): array
{
    if ($depth > 4) {
        return [];
    }

    $payload = [];
    $arrayChildren = [];

    foreach ($rules as $field => $fieldRules) {
        if (str_contains($field, '.*.')) {
            [$parent, $child] = explode('.*.', $field, 2);
            $arrayChildren[$parent][$child] = $fieldRules;

            continue;
        }

        if (str_ends_with($field, '.*')) {
            continue;
        }

        $payload[$field] = exampleValue($field, $fieldRules, $depth);
    }

    foreach ($arrayChildren as $parent => $children) {
        $buildItem = static function (array $overrides = []) use ($children, $depth): array {
            $item = [];
            foreach ($children as $child => $childRules) {
                $item[$child] = exampleValue($child, $childRules, $depth + 1);
            }

            return array_merge($item, $overrides);
        };

        if ($parent === 'items' && isset($children['menu_item_id'])) {
            $payload[$parent] = [
                $buildItem(['notes' => 'كشري', 'menu_item_id' => 1, 'quantity' => 2]),
                $buildItem(['notes' => 'فول', 'menu_item_id' => 2, 'quantity' => 1]),
            ];
        } elseif ($parent === 'items' && isset($children['ingredient_id'])) {
            $payload[$parent] = [
                $buildItem(['ingredient_id' => 1, 'quantity' => 5.0, 'unit_cost' => 12.50]),
                $buildItem(['ingredient_id' => 2, 'quantity' => 2.0, 'unit_cost' => 8.00]),
            ];
        } elseif ($parent === 'splits') {
            $payload[$parent] = [
                $buildItem(['method' => 'cash', 'amount' => 25.00]),
                $buildItem(['method' => 'vodafone_cash', 'amount' => 20.00]),
            ];
        } else {
            $payload[$parent] = [$buildItem()];
        }
    }

    return $payload;
}

function exampleValue(string $field, array $rules, int $depth = 0): mixed
{
    $key = strtolower(preg_replace('/\.\*\.[^.]+$/', '', $field) ?? $field);

    $exact = [
        'name' => 'Downtown Branch',
        'reorder_level' => 10,
        'sort_order' => 1,
        'current_stock' => 25,
        'preparation_time' => 15,
        'capacity' => 4,
        'position_x' => 0,
        'position_y' => 0,
        'opening_float' => 500,
        'closing_cash_count' => 450,
        'points' => 100,
        'loyalty_points' => 50,
        'trial_days' => 14,
        'limit' => 10,
        'per_page' => 25,
    ];

    if (isset($exact[$key])) {
        return $exact[$key];
    }

    $arabic = [
        'restaurant_name' => 'مطعم النيل',
        'branch_name' => 'فرع وسط البلد',
        'branch_name_ar' => 'فرع وسط البلد',
        'branch_address' => 'شارع قصر النيل، وسط البلد، القاهرة',
        'supplier_name' => 'مورد الأغذية المتحدة',
        'name_ar' => 'كشري',
        'name_en' => 'Koshary',
        'owner_name' => 'محمد أحمد',
        'customer_name' => 'أحمد محمود',
        'delivery_address' => '١٢ شارع التحرير، الدقي، الجيزة',
        'address' => 'منطقة المعادي، القاهرة',
        'notes' => 'بدون بصل',
        'discount_reason' => 'خصم موظف',
        'reason' => 'طلب العميل إلغاء الطلب',
        'table_label' => 'طاولة ٥',
        'custom_domain' => 'menu.nilerestaurant.com',
        'device_name' => 'تابلت الكاشير ١',
        'campaign_name' => 'عرض رمضان',
        'message' => 'مرحباً! خصم ١٠٪ على الطلبات هذا الأسبوع',
        'title' => 'تقرير أسبوعي',
    ];

    if (isset($arabic[$key])) {
        return $arabic[$key];
    }

    if (str_contains($key, 'email')) {
        return 'owner@nilerestaurant.eg';
    }
    if (str_contains($key, 'phone')) {
        return '+201012345678';
    }
    if (str_contains($key, 'password')) {
        return 'SecurePass123!';
    }
    if (str_contains($key, 'pin')) {
        return '1234';
    }
    if (str_contains($key, 'subdomain')) {
        return 'nilerestaurant';
    }
    if (str_contains($key, 'timezone')) {
        return 'Africa/Cairo';
    }
    if (str_contains($key, 'locale')) {
        return 'ar';
    }
    if (str_contains($key, 'token')) {
        return '{{qr_token}}';
    }
    if (str_contains($key, 'secret')) {
        return 'kitchen-secret-12345678';
    }
    if (in_array('boolean', $rules, true)) {
        return true;
    }
    if (in_array('array', $rules, true)) {
        return [];
    }

    foreach ($rules as $rule) {
        if (str_starts_with($rule, 'in:')) {
            return explode(',', substr($rule, 3))[0];
        }
    }

    if (in_array('integer', $rules, true) || in_array('numeric', $rules, true)) {
        if (str_contains($key, 'amount') || str_contains($key, 'cost') || str_contains($key, 'price')) {
            return 45.00;
        }
        if (str_contains($key, 'quantity')) {
            return 2;
        }

        $idFields = [
            'branch_id' => '{{branch_id}}',
            'order_id' => '{{order_id}}',
            'customer_id' => '{{customer_id}}',
            'supplier_id' => '{{supplier_id}}',
            'ingredient_id' => '{{ingredient_id}}',
            'menu_item_id' => '{{menu_item_id}}',
            'category_id' => '{{category_id}}',
            'rider_id' => '{{rider_id}}',
            'floor_table_id' => '{{table_id}}',
            'causer_id' => '{{user_id}}',
            'user_id' => '{{user_id}}',
        ];

        if (isset($idFields[$key])) {
            return $idFields[$key];
        }

        return 1;
    }

    return 'مثال';
}

function formatRulesTable(array $rules): string
{
    if ($rules === []) {
        return '_None_';
    }

    $lines = ['| Parameter | Rules |', '|-----------|-------|'];
    foreach ($rules as $field => $fieldRules) {
        $lines[] = sprintf('| `%s` | `%s` |', $field, implode(', ', $fieldRules));
    }

    return implode("\n", $lines);
}

function jsonPretty(mixed $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $json ?: '{}';
}

// ── Markdown generation ──────────────────────────────────────────────────────

/** @param list<array<string, mixed>> $endpoints */
function generateMarkdown(array $endpoints, array $requestMap, array $allRules): string
{
    $header = <<<'MD'
# Restaurant SaaS API Reference

REST API for restaurant & café management — Egypt & MENA market.

**Base URL:** `{{base_url}}` (default: `http://localhost:8000/api/v1`)

> Generated by `tools/generate-api-documentation.php`. Do not edit manually.

---

## Introduction

This API powers multi-tenant restaurant operations: POS, delivery, inventory, loyalty, and platform administration. All responses are JSON.

Supported locales: `ar` (Arabic, default for Egypt) and `en`.

---

## Authentication

### Tenant users (owners, managers, staff)

1. **Register** via `POST /onboarding/register` (no auth) or log in via `POST /auth/login`.
2. Receive a Sanctum Bearer token: `Authorization: Bearer {token}`.
3. Resolve tenant context on every request using one of:
   - Subdomain host: `{subdomain}.restoapp.eg`
   - Verified custom domain
   - Header: `X-Tenant-Subdomain: {subdomain}` (required for mobile/API clients without subdomain DNS)

### Device login (waiter / cashier / kitchen)

- `POST /auth/device/login` — PIN + branch, requires tenant header
- `POST /auth/device/kitchen` — kitchen display secret, requires tenant header

### Platform admin

- `POST /admin/auth/login` → use `Authorization: Bearer {admin_token}`
- No tenant header required

### Email verification

Most tenant routes require verified email (`verified.email` middleware). Verify via `GET /auth/verify-email` link or resend with `POST /auth/email/verification-notification`.

---

## Tenant Resolution

Priority order (see `TenantResolver`):

1. Authenticated user's tenant (from token)
2. Verified custom domain (`Host` header)
3. `X-Tenant-Subdomain` header
4. Subdomain extracted from `{subdomain}.{base_domain}`
5. First subdomain label from host

Failure returns:

```json
{
  "errors": [{ "message": "Tenant not found.", "code": "TENANT_NOT_FOUND" }]
}
```

---

## Response Envelope

### Success

```json
{
  "data": { "id": 1, "name": "مطعم النيل" },
  "meta": { "message": "OK" }
}
```

### Paginated success

```json
{
  "data": [{ "id": 1, "name": "كشري" }],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 25,
    "total": 62,
    "message": "OK"
  }
}
```

### Error

```json
{
  "errors": [
    { "message": "Validation failed.", "code": "VALIDATION_ERROR", "field": "email" }
  ]
}
```

---

## Error Codes

| HTTP | Code | Description |
|------|------|-------------|
MD;

    foreach (ERROR_CODES as [$status, $code, $desc]) {
        $header .= "\n| {$status} | `{$code}` | {$desc} |";
    }

    $header .= "\n\n---\n\n## Plan Features\n\n| Feature | Starter | Growth | Pro | Enterprise |\n|---------|:-------:|:------:|:---:|:----------:|\n";

    $allFeatures = PLAN_FEATURES['enterprise'];
    sort($allFeatures);

    foreach ($allFeatures as $feature) {
        $row = "| `{$feature}`";
        foreach (['starter', 'growth', 'pro', 'enterprise'] as $plan) {
            $row .= in_array($feature, PLAN_FEATURES[$plan], true) ? ' | ✓' : ' | —';
        }
        $header .= $row." |\n";
    }

    $header .= "\n---\n\n## Endpoints\n";

    $byModule = [];
    foreach ($endpoints as $endpoint) {
        $byModule[$endpoint['module']][] = $endpoint;
    }

    $body = $header;

    foreach ($byModule as $module => $moduleEndpoints) {
        $body .= "\n### {$module}\n";

        foreach ($moduleEndpoints as $endpoint) {
            $rules = rulesForEndpoint($endpoint, $requestMap, $allRules);
            $description = endpointDescription($endpoint);
            $auth = $endpoint['auth']['label'];
            $permissions = $endpoint['permissions'] !== [] ? '`'.implode('`, `', $endpoint['permissions']).'`' : '_None_';
            $features = $endpoint['features'] !== [] ? '`'.implode('`, `', $endpoint['features']).'`' : '_None_';

            $pathParams = $endpoint['path_params'] !== []
                ? implode(', ', array_map(static fn ($p) => "`{{$p}}`", $endpoint['path_params']))
                : '_None_';

            $exampleBody = WEBHOOK_BODIES[$endpoint['uri']] ?? buildExamplePayload($rules['body']);
            if ($rules['body'] === [] && $endpoint['method'] !== 'GET') {
                $exampleBody = $exampleBody ?: new stdClass;
            }

            $body .= "\n#### `{$endpoint['method']}` {$endpoint['path']}\n\n";
            $body .= "{$description}\n\n";
            $body .= "- **Auth:** {$auth}\n";
            $body .= "- **Permissions:** {$permissions}\n";
            $body .= "- **Plan features:** {$features}\n";
            $body .= "- **Path params:** {$pathParams}\n\n";

            if ($rules['query'] !== []) {
                $body .= "**Query parameters**\n\n".formatRulesTable($rules['query'])."\n\n";
            }

            if ($rules['body'] !== [] || in_array($endpoint['method'], ['POST', 'PUT', 'PATCH'], true)) {
                $body .= "**Request body**\n\n".formatRulesTable($rules['body'])."\n\n";
                if ($exampleBody !== []) {
                    $body .= "```json\n".jsonPretty($exampleBody)."\n```\n\n";
                }
            }

            $body .= "---\n";
        }
    }

    return $body;
}

// ── Postman generation ───────────────────────────────────────────────────────

/** @return list<string> */
function resolvePostmanFolderPath(array $endpoint): array
{
    $uri = $endpoint['uri'];

    if ($uri === 'api/v1/onboarding/register') {
        return ['01 · Onboarding & Auth', '1.1 Register Restaurant'];
    }

    if (str_starts_with($uri, 'api/v1/admin/auth/')) {
        return ['09 · Platform Admin', '9.1 Admin Auth'];
    }
    if (str_starts_with($uri, 'api/v1/admin/dashboard')) {
        return ['09 · Platform Admin', '9.2 Dashboard'];
    }
    if (str_starts_with($uri, 'api/v1/admin/tenants')) {
        return ['09 · Platform Admin', '9.3 Tenant Management'];
    }

    if (str_starts_with($uri, 'api/v1/webhook/')) {
        $sub = match (true) {
            str_contains($uri, 'whatsapp') => '10.1 WhatsApp',
            str_contains($uri, 'paymob') => '10.2 Paymob Billing',
            str_contains($uri, 'fawry') => '10.3 Fawry Billing',
            str_contains($uri, 'talabat') => '10.4 Talabat Aggregator',
            str_contains($uri, 'elmenus') => '10.5 Elmenus Aggregator',
            default => '10.0 Other',
        };

        return ['10 · Webhooks (Inbound)', $sub];
    }

    if (str_starts_with($uri, 'api/v1/qr/')) {
        return ['06 · Delivery & QR', '6.3 Public QR Menu (no auth)'];
    }

    if (str_starts_with($uri, 'api/v1/auth/')) {
        return ['01 · Onboarding & Auth', '1.2 Login & Session'];
    }

    if (str_starts_with($uri, 'api/v1/ops/')) {
        return ['01 · Onboarding & Auth', '1.0 Health & Environment'];
    }

    if (str_starts_with($uri, 'api/v1/settings/eta')) {
        return ['02 · Tenant Setup', '2.7 ETA Configuration'];
    }
    if (str_starts_with($uri, 'api/v1/settings')) {
        return ['02 · Tenant Setup', '2.1 Settings & Custom Domain'];
    }
    if (str_starts_with($uri, 'api/v1/branches')) {
        return ['02 · Tenant Setup', '2.2 Branches'];
    }
    if (str_starts_with($uri, 'api/v1/staff/shifts')) {
        return ['02 · Tenant Setup', '2.4 Staff Shifts'];
    }
    if (str_starts_with($uri, 'api/v1/staff')) {
        return ['02 · Tenant Setup', '2.3 Staff'];
    }
    if (str_starts_with($uri, 'api/v1/subscription')) {
        return ['02 · Tenant Setup', '2.5 Subscription & Billing'];
    }
    if (str_starts_with($uri, 'api/v1/audit-log')) {
        return ['02 · Tenant Setup', '2.6 Audit Log'];
    }

    if (str_starts_with($uri, 'api/v1/menu/categories')) {
        return ['03 · Menu & Floor Setup', '3.1 Menu Categories'];
    }
    if (str_starts_with($uri, 'api/v1/menu/items') && ! str_contains($uri, '/recipe') && ! str_contains($uri, '/cost')) {
        return ['03 · Menu & Floor Setup', '3.2 Menu Items'];
    }
    if (str_starts_with($uri, 'api/v1/tables')) {
        return ['03 · Menu & Floor Setup', '3.3 Floor Tables'];
    }

    if (str_contains($uri, '/orders/{order}/pay') || str_contains($uri, '/orders/{order}/refund')) {
        return ['04 · Daily Operations (POS)', '4.4 Payments & Refunds'];
    }
    if (str_contains($uri, '/orders/{order}/items')) {
        return ['04 · Daily Operations (POS)', '4.2 Order Items'];
    }
    if (str_contains($uri, 'assign-rider') || str_contains($uri, 'delivery-status')) {
        return ['06 · Delivery & QR', '6.2 Riders & Delivery Status'];
    }
    if (str_starts_with($uri, 'api/v1/orders')) {
        return ['04 · Daily Operations (POS)', '4.1 Orders'];
    }
    if (str_starts_with($uri, 'api/v1/kitchen')) {
        return ['04 · Daily Operations (POS)', '4.3 Kitchen Display'];
    }
    if (str_contains($uri, '/print/')) {
        return ['04 · Daily Operations (POS)', '4.5 Print Tickets'];
    }
    if (str_starts_with($uri, 'api/v1/invoices')) {
        return ['04 · Daily Operations (POS)', '4.6 ETA Invoices'];
    }

    if (str_starts_with($uri, 'api/v1/reports/daily')
        || str_starts_with($uri, 'api/v1/reports/cash-summary')
        || str_starts_with($uri, 'api/v1/reports/top-items')) {
        return ['05 · Reports', '5.1 Daily & Sales Reports'];
    }
    if (str_starts_with($uri, 'api/v1/reports/branches')) {
        return ['05 · Reports', '5.2 Branch Comparison'];
    }
    if (str_starts_with($uri, 'api/v1/reports/ai-summary')) {
        return ['08 · Intelligence', '8.4 AI Weekly Summary'];
    }

    if (str_starts_with($uri, 'api/v1/customers')) {
        return ['06 · Delivery & QR', '6.1 Customers'];
    }
    if (str_starts_with($uri, 'api/v1/riders')) {
        return ['06 · Delivery & QR', '6.2 Riders & Delivery Status'];
    }

    if (str_contains($uri, '/recipe') || str_contains($uri, '/cost')) {
        return ['07 · Inventory', '7.4 Recipes & Costing'];
    }
    if (str_starts_with($uri, 'api/v1/inventory/suppliers')
        || str_contains($uri, 'purchase-orders')) {
        return ['07 · Inventory', '7.5 Suppliers & Purchase Orders'];
    }
    if (str_starts_with($uri, 'api/v1/inventory/transfers')) {
        return ['07 · Inventory', '7.6 Stock Transfers'];
    }
    if (str_starts_with($uri, 'api/v1/inventory/stock-counts')) {
        return ['07 · Inventory', '7.3 Stock Counts'];
    }
    if (str_starts_with($uri, 'api/v1/inventory/movements')) {
        return ['07 · Inventory', '7.2 Stock Movements'];
    }
    if (str_starts_with($uri, 'api/v1/inventory/ingredients') || str_starts_with($uri, 'api/v1/inventory/low-stock')) {
        return ['07 · Inventory', '7.1 Ingredients'];
    }

    if (str_starts_with($uri, 'api/v1/loyalty')) {
        return ['08 · Intelligence', '8.1 Loyalty'];
    }
    if (str_starts_with($uri, 'api/v1/marketing')) {
        return ['08 · Intelligence', '8.2 WhatsApp Marketing'];
    }
    if (str_starts_with($uri, 'api/v1/analytics')) {
        return ['08 · Intelligence', '8.3 Aggregator Analytics'];
    }

    return ['99 · Other', str_replace('api/v1/', '', $uri)];
}

function postmanRequestDisplayName(array $endpoint): string
{
    $labels = [
        'App\\Modules\\Tenant\\Http\\Controllers\\OnboardingController@register' => 'Register restaurant (onboarding)',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@login' => 'Login — email & password (owner/manager)',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@deviceLogin' => 'Device login — PIN (waiter/cashier)',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@kitchenLogin' => 'Kitchen display login — device secret',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@me' => 'Get current user (me)',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@logout' => 'Logout — revoke token',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@forgotPassword' => 'Forgot password',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@resetPassword' => 'Reset password',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@verifyEmail' => 'Verify email (link callback)',
        'App\\Modules\\Auth\\Http\\Controllers\\AuthController@sendVerificationNotification' => 'Resend verification email',
        'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@store' => 'Place order',
        'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@index' => 'List orders',
        'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@show' => 'Get order details',
        'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@update' => 'Update order notes',
        'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@updateStatus' => 'Update order status',
        'App\\Modules\\POS\\Billing\\Http\\Controllers\\PaymentController@settle' => 'Settle payment',
        'App\\Modules\\POS\\Billing\\Http\\Controllers\\PaymentController@refund' => 'Refund payment',
    ];

    if (isset($labels[$endpoint['action_key']])) {
        return $endpoint['method'].' · '.$labels[$endpoint['action_key']];
    }

    $path = str_replace('api/v1/', '', $endpoint['uri']);

    return $endpoint['method'].' · '.$path;
}

/** @param list<array<string, mixed>> $endpoints */
function sortEndpointsForPostman(array $endpoints): array
{
    $methodOrder = ['GET' => 1, 'POST' => 2, 'PUT' => 3, 'PATCH' => 4, 'DELETE' => 5];

    usort($endpoints, static function (array $a, array $b) use ($methodOrder): int {
        $ma = $methodOrder[$a['method']] ?? 9;
        $mb = $methodOrder[$b['method']] ?? 9;
        if ($ma !== $mb) {
            return $ma <=> $mb;
        }

        return [$a['path'], $a['method']] <=> [$b['path'], $b['method']];
    });

    return $endpoints;
}

/**
 * @param list<array<string, mixed>> $endpoints
 * @return array<string, array{items: list<array<string, mixed>>, children: array<string, mixed>}>
 */
function groupEndpointsByFolderPath(array $endpoints): array
{
    $tree = [];

    foreach ($endpoints as $endpoint) {
        $path = resolvePostmanFolderPath($endpoint);
        $node = &$tree;

        foreach ($path as $index => $segment) {
            if (! isset($node[$segment])) {
                $node[$segment] = ['items' => [], 'children' => []];
            }

            if ($index === count($path) - 1) {
                $node[$segment]['items'][] = $endpoint;
            } else {
                $node = &$node[$segment]['children'];
            }
        }
    }

    return $tree;
}

/**
 * @param array<string, array{items: list<array<string, mixed>>, children: array<string, mixed>}> $tree
 * @return list<array<string, mixed>>
 */
function postmanTreeToItems(array $tree, array $requestMap, array $allRules): array
{
    ksort($tree);
    $items = [];

    foreach ($tree as $name => $node) {
        $childFolders = postmanTreeToItems($node['children'], $requestMap, $allRules);
        $requests = array_map(
            static fn (array $ep) => postmanRequestItem($ep, $requestMap, $allRules),
            sortEndpointsForPostman($node['items']),
        );

        $folder = [
            'name' => $name,
            'item' => array_merge($childFolders, $requests),
        ];

        if ($childFolders === [] && $requests !== []) {
            $folder['description'] = folderDescription($name);
        }

        $items[] = $folder;
    }

    return $items;
}

function folderDescription(string $folderName): string
{
    return match (true) {
        str_contains($folderName, 'Register') => 'Step 1: Create tenant, owner, default branch. Saves token, subdomain, branch_id, kitchen_device_secret.',
        str_contains($folderName, 'Login') => 'Step 2: Authenticate. Owner uses email login; staff tablets use PIN; kitchen screen uses device secret + X-Tenant-Subdomain.',
        str_contains($folderName, 'Settings') => 'Restaurant profile, locale, WhatsApp, custom domain DNS verification.',
        str_contains($folderName, 'Branches') => 'Manage locations. Each branch gets a qr_menu_url for shareable menus.',
        str_contains($folderName, 'Staff') && ! str_contains($folderName, 'Shifts') => 'Create staff with roles and 4-digit PINs for tablet login.',
        str_contains($folderName, 'Shifts') => 'Clock in/out for cashiers. Required before cash payments on Pro plan.',
        str_contains($folderName, 'Menu Categories') => 'Setup menu structure before adding items.',
        str_contains($folderName, 'Menu Items') => 'Add dishes with Arabic names, prices, photos.',
        str_contains($folderName, 'Floor Tables') => 'Table layout for dine-in orders and table QR codes.',
        str_contains($folderName, '4.1 Orders') => 'Core POS flow: list → create → update status.',
        str_contains($folderName, 'Payments') => 'Settle and refund after order is ready.',
        str_contains($folderName, 'Kitchen') => 'Kitchen queue and mark items done.',
        str_contains($folderName, 'QR Menu') => 'Public endpoints — no auth. Token from branch qr_menu_url or table QR.',
        default => '',
    };
}

/** @param list<array<string, mixed>> $endpoints */
function generatePostmanCollection(array $endpoints, array $requestMap, array $allRules): array
{
    $tree = groupEndpointsByFolderPath($endpoints);
    $referenceItems = postmanTreeToItems($tree, $requestMap, $allRules);

    $items = [
        buildScenarioFolder($endpoints, $requestMap, $allRules),
        ...$referenceItems,
    ];

    return [
        'info' => [
            '_postman_id' => 'restaurant-saas-api-v1',
            'name' => 'Restaurant SaaS API',
            'description' => <<<'DESC'
Frontend integration guide — run folders top to bottom.

**Start here:** `00 · Quick Start Flows` → run subfolders in order (onboarding saves token + subdomain automatically).

**Then:** `01` Register/Auth → `02` Tenant setup → `03` Menu & tables → `04` Daily POS operations.

**Collection variables** (auto-set by test scripts where noted):
- `tenant_subdomain` — required on all tenant routes (or use subdomain host)
- `tenant_token` — Bearer token after login/register
- `branch_id`, `order_id`, `menu_item_id`, … — set after create responses
- `kitchen_device_secret` — from onboarding response (shown once)

Regenerate: `php tools/generate-api-documentation.php`
DESC,
            'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        ],
        'variable' => [
            ['key' => 'base_url', 'value' => 'http://localhost:8000/api/v1'],
            ['key' => 'tenant_subdomain', 'value' => 'nilerestaurant'],
            ['key' => 'tenant_token', 'value' => ''],
            ['key' => 'admin_token', 'value' => ''],
            ['key' => 'kitchen_device_secret', 'value' => ''],
            ['key' => 'branch_id', 'value' => '1'],
            ['key' => 'order_id', 'value' => '1'],
            ['key' => 'menu_item_id', 'value' => '1'],
            ['key' => 'customer_id', 'value' => '1'],
            ['key' => 'supplier_id', 'value' => '1'],
            ['key' => 'ingredient_id', 'value' => '1'],
            ['key' => 'purchase_order_id', 'value' => '1'],
            ['key' => 'qr_token', 'value' => 'table-token-example'],
            ['key' => 'staff_id', 'value' => '1'],
            ['key' => 'table_id', 'value' => '1'],
            ['key' => 'category_id', 'value' => '1'],
        ],
        'item' => $items,
    ];
}

function postmanRequestItem(array $endpoint, array $requestMap, array $allRules): array
{
    $rules = rulesForEndpoint($endpoint, $requestMap, $allRules);
    $exampleBody = WEBHOOK_BODIES[$endpoint['uri']] ?? buildExamplePayload($rules['body']);

    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
        ['key' => 'Content-Type', 'value' => 'application/json'],
    ];

    foreach ($endpoint['auth']['headers'] as $key => $value) {
        $headers[] = ['key' => $key, 'value' => $value];
    }

    $relativePath = preg_replace('#^api/v1/#', '', $endpoint['uri']) ?? $endpoint['uri'];
    $url = '{{base_url}}/'.$relativePath;
    $url = str_replace(
        ['{tenant}', '{branch}', '{order}', '{item}', '{customer}', '{supplier}', '{purchaseOrder}', '{stockCount}', '{invoice}', '{shift}', '{staff}', '{category}', '{table}', '{ingredient}', '{token}'],
        ['{{tenant_id}}', '{{branch_id}}', '{{order_id}}', '{{menu_item_id}}', '{{customer_id}}', '{{supplier_id}}', '{{purchase_order_id}}', '{{stock_count_id}}', '{{invoice_id}}', '{{shift_id}}', '{{staff_id}}', '{{category_id}}', '{{table_id}}', '{{ingredient_id}}', '{{qr_token}}'],
        $url,
    );

    $query = [];
    foreach ($rules['query'] as $field => $fieldRules) {
        $query[] = [
            'key' => $field,
            'value' => is_string(exampleValue($field, $fieldRules)) ? (string) exampleValue($field, $fieldRules) : json_encode(exampleValue($field, $fieldRules)),
            'description' => implode(', ', $fieldRules),
        ];
    }

    $pathSegments = array_values(array_filter(explode('/', $relativePath)));

    $request = [
        'method' => $endpoint['method'],
        'header' => $headers,
        'url' => [
            'raw' => $url.($query ? '?'.http_build_query(array_column($query, 'value', 'key')) : ''),
            'host' => ['{{base_url}}'],
            'path' => $pathSegments,
            'query' => $query,
        ],
        'description' => endpointDescription($endpoint)."\n\nAuth: ".$endpoint['auth']['label'],
    ];

    if ($rules['body'] !== [] || isset(WEBHOOK_BODIES[$endpoint['uri']])) {
        $request['body'] = [
            'mode' => 'raw',
            'raw' => jsonPretty($exampleBody),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    $events = [];
    if (in_array($endpoint['auth']['type'], ['bearer_tenant', 'tenant_header'], true)) {
        $tenantHeader = (string) config('tenant.subdomain_header', 'X-Tenant-Subdomain');
        $events[] = [
            'listen' => 'prerequest',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    "if (!pm.collectionVariables.get('tenant_subdomain')) {",
                    "    console.warn('Set tenant_subdomain collection variable');",
                    '}',
                    "pm.request.headers.upsert({ key: '{$tenantHeader}', value: pm.collectionVariables.get('tenant_subdomain') });",
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\Auth\\Http\\Controllers\\AuthController@login') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    "const json = pm.response.json();",
                    "if (json.data && json.data.token) {",
                    "    pm.collectionVariables.set('tenant_token', json.data.token);",
                    '}',
                    "if (json.data?.user?.tenant_id) {",
                    "    pm.collectionVariables.set('tenant_id', json.data.user.tenant_id);",
                    '}',
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\Tenant\\Http\\Controllers\\OnboardingController@register') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    "const json = pm.response.json();",
                    "if (json.data?.token) {",
                    "    pm.collectionVariables.set('tenant_token', json.data.token);",
                    '}',
                    "if (json.data?.tenant?.subdomain) {",
                    "    pm.collectionVariables.set('tenant_subdomain', json.data.tenant.subdomain);",
                    '}',
                    "if (json.data?.branch?.id) {",
                    "    pm.collectionVariables.set('branch_id', json.data.branch.id);",
                    '}',
                    "if (json.data?.kitchen_device_secret) {",
                    "    pm.collectionVariables.set('kitchen_device_secret', json.data.kitchen_device_secret);",
                    '}',
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\Platform\\Http\\Controllers\\AdminAuthController@login') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    "const json = pm.response.json();",
                    "if (json.data && json.data.token) {",
                    "    pm.collectionVariables.set('admin_token', json.data.token);",
                    '}',
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@store') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    "const json = pm.response.json();",
                    "if (json.data && json.data.id) {",
                    "    pm.collectionVariables.set('order_id', json.data.id);",
                    '}',
                ],
            ],
        ];
    }

    $item = [
        'name' => postmanRequestDisplayName($endpoint),
        'request' => $request,
    ];

    if ($events !== []) {
        $item['event'] = $events;
    }

    return $item;
}

/** @param list<array<string, mixed>> $endpoints */
function buildScenarioFolder(array $endpoints, array $requestMap, array $allRules): array
{
    $find = static function (string $method, string $pathSuffix) use ($endpoints): ?array {
        foreach ($endpoints as $ep) {
            $relative = preg_replace('#^api/v1/#', '', $ep['uri']) ?? $ep['uri'];
            if ($ep['method'] === $method && $relative === $pathSuffix) {
                return $ep;
            }
        }

        return null;
    };

    $scenarios = [
        '01 · Register & login' => [
            $find('POST', 'onboarding/register'),
            $find('POST', 'auth/login'),
        ],
        '02 · Device login (PIN + kitchen)' => [
            $find('POST', 'auth/device/login'),
            $find('POST', 'auth/device/kitchen'),
        ],
        '03 · Setup menu & tables' => [
            $find('POST', 'menu/categories'),
            $find('POST', 'menu/items'),
            $find('POST', 'tables'),
        ],
        '04 · Order flow (place → pay → refund)' => [
            $find('POST', 'orders'),
            $find('POST', 'orders/{order}/pay'),
            $find('POST', 'orders/{order}/refund'),
        ],
        '05 · QR order (public)' => [
            $find('GET', 'qr/{token}/menu'),
            $find('POST', 'qr/{token}/orders'),
        ],
        '06 · Custom domain verify' => [
            $find('PATCH', 'settings'),
            $find('GET', 'settings/domain'),
            $find('POST', 'settings/domain/verify'),
        ],
        '07 · Staff shift' => [
            $find('POST', 'staff/shifts/clock-in'),
            $find('POST', 'staff/shifts/clock-out'),
        ],
        '08 · Inventory PO flow' => [
            $find('POST', 'inventory/suppliers'),
            $find('POST', 'inventory/purchase-orders'),
            $find('POST', 'inventory/purchase-orders/{purchaseOrder}/submit'),
            $find('POST', 'inventory/purchase-orders/{purchaseOrder}/receive'),
        ],
    ];

    $scenarioBodyOverrides = [
        '01 · Register & login' => [
            'onboarding/register' => [
                'restaurant_name' => 'مطعم النيل',
                'locale' => 'ar',
                'owner_name' => 'محمد أحمد',
                'owner_email' => 'owner@nilerestaurant.eg',
                'owner_password' => 'SecurePass123!',
                'owner_phone' => '+201012345678',
                'branch_name' => 'فرع وسط البلد',
                'branch_name_ar' => 'فرع وسط البلد',
                'branch_address' => 'شارع قصر النيل، وسط البلد، القاهرة',
                'branch_phone' => '+201012345678',
                'timezone' => 'Africa/Cairo',
                'kitchen_device_secret' => 'kitchen-secret-12345678',
            ],
        ],
    ];

    $items = [];
    foreach ($scenarios as $name => $scenarioEndpoints) {
        $scenarioItems = [];
        foreach (array_filter($scenarioEndpoints) as $ep) {
            $item = postmanRequestItem($ep, $requestMap, $allRules);
            $pathKey = preg_replace('#^api/v1/#', '', $ep['uri']) ?? $ep['uri'];
            if (isset($scenarioBodyOverrides[$name][$pathKey])) {
                $item['request']['body']['raw'] = jsonPretty($scenarioBodyOverrides[$name][$pathKey]);
            }
            $scenarioItems[] = $item;
        }
        $items[] = ['name' => $name, 'item' => $scenarioItems];
    }

    return [
        'name' => '00 · Quick Start Flows',
        'description' => 'Run these subfolders **in order** when testing the full frontend journey. Each step saves variables for the next (token, subdomain, branch_id, order_id).',
        'item' => $items,
    ];
}

function generatePostmanEnvironment(): array
{
    return [
        'id' => 'restaurant-saas-env',
        'name' => 'Restaurant SaaS — Local',
        'values' => [
            ['key' => 'base_url', 'value' => 'http://localhost:8000/api/v1', 'type' => 'default', 'enabled' => true],
            ['key' => 'tenant_subdomain', 'value' => 'nilerestaurant', 'type' => 'default', 'enabled' => true],
            ['key' => 'tenant_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
            ['key' => 'admin_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
            ['key' => 'kitchen_device_secret', 'value' => '', 'type' => 'secret', 'enabled' => true],
            ['key' => 'branch_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'order_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'menu_item_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'customer_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'supplier_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'ingredient_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'purchase_order_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'qr_token', 'value' => 'table-token-example', 'type' => 'default', 'enabled' => true],
            ['key' => 'staff_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'table_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'category_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'invoice_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'shift_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'tenant_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
        ],
        '_postman_variable_scope' => 'environment',
        '_postman_exported_at' => gmdate('c'),
        '_postman_exported_using' => 'generate-api-documentation.php',
    ];
}

// ── Main ─────────────────────────────────────────────────────────────────────

$requestMap = mapControllerFormRequests($basePath);
$allRules = loadFormRequestRules($basePath);
$endpoints = collectEndpoints(app('router'));

$docsDir = $basePath.'/docs';
$postmanDir = $docsDir.'/postman';
if (! is_dir($postmanDir) && ! mkdir($postmanDir, 0755, true) && ! is_dir($postmanDir)) {
    fwrite(STDERR, "Failed to create {$postmanDir}\n");
    exit(1);
}

$apiMd = generateMarkdown($endpoints, $requestMap, $allRules);
$collection = generatePostmanCollection($endpoints, $requestMap, $allRules);
$environment = generatePostmanEnvironment();

$apiMdPath = $docsDir.'/API.md';
$collectionPath = $postmanDir.'/Restaurant-SaaS-API.postman_collection.json';
$environmentPath = $postmanDir.'/Restaurant-SaaS-Environment.postman_environment.json';

file_put_contents($apiMdPath, $apiMd);
file_put_contents($collectionPath, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($environmentPath, json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

// Validate JSON
$collectionJson = json_decode((string) file_get_contents($collectionPath), true);
$environmentJson = json_decode((string) file_get_contents($environmentPath), true);

if (json_last_error() !== JSON_ERROR_NONE || ! is_array($collectionJson) || ! is_array($environmentJson)) {
    fwrite(STDERR, 'JSON validation failed: '.json_last_error_msg()."\n");
    exit(1);
}

$endpointCount = count($endpoints);
$moduleCounts = [];
foreach ($endpoints as $ep) {
    $moduleCounts[$ep['module']] = ($moduleCounts[$ep['module']] ?? 0) + 1;
}

echo "Generated API documentation\n";
echo "===========================\n";
echo "Endpoints: {$endpointCount}\n";
echo "Files:\n";
echo "  - {$apiMdPath}\n";
echo "  - {$collectionPath}\n";
echo "  - {$environmentPath}\n";
echo "\nBy module:\n";
ksort($moduleCounts);
foreach ($moduleCounts as $module => $count) {
    echo "  - {$module}: {$count}\n";
}
echo "\nJSON validation: OK\n";
