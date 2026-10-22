<?php

declare(strict_types=1);
use App\Shared\Support\Http\Requests\Concerns\HasPaginationRules;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Router;

/**
 * Generates API.md and Postman collection/environment for all api/v1/* endpoints.
 *
 * Usage: php tools/generate-api-documentation.php
 */
$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ── Plan features (mirrors Tenant::planIncludesFeature) ────────────────────────

const PLAN_FEATURES = [
    'starter' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice'],
    'growth' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice', 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders', 'menu_packages'],
    'pro' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice', 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders', 'menu_packages', 'inventory', 'recipe_costing', 'suppliers', 'staff_shifts', 'audit_log', 'waste_log', 'offers'],
    'enterprise' => ['pos', 'kitchen_display', 'daily_reports', 'eta_invoice', 'qr_menu', 'whatsapp_ordering', 'delivery', 'customers', 'riders', 'menu_packages', 'inventory', 'recipe_costing', 'suppliers', 'staff_shifts', 'audit_log', 'waste_log', 'offers', 'multi_branch', 'ai_reports', 'loyalty', 'whatsapp_marketing', 'aggregator_analytics'],
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
    ['422', 'ORDER_VALIDATION_FAILED', 'An offline or online order failed business validation'],
    ['422', 'INGREDIENT_ERROR', 'Ingredient catalog missing, already stocked at that branch, or identity update failed'],
    ['422', 'STOCK_TRANSFER_FAILED', 'Invalid transfer state, quantity, or insufficient source stock'],
    ['404', 'NO_OPEN_BUSINESS_DAY', 'No open business day exists for the requested branch'],
    ['422', 'BUSINESS_DAY_OPEN_FAILED', 'A business day is already open for this branch'],
    ['422', 'BUSINESS_DAY_CLOSE_FAILED', 'Shifts or open orders still exist, or the day is already closed'],
    ['422', 'STOCK_RETURN_FAILED', 'Remaining stock could not be returned to main inventory'],
    ['400', 'ERROR', 'Generic client error'],
    ['400', 'TENANT_REQUIRED', 'X-Tenant-Subdomain header required'],
    ['401', 'HUB_UNAUTHENTICATED', 'Missing or invalid X-Hub-Token on a LAN hub route'],
    ['404', 'HUB_DISABLED', 'LAN POS hub is not enabled on this server'],
    ['404', 'HUB_SNAPSHOT_MISSING', 'No catalog snapshot is cached on the LAN hub yet'],
    ['422', 'HUB_VALIDATION_FAILED', 'A LAN hub operation referenced a missing local order'],
    ['422', 'HUB_CLOUD_NOT_CONFIGURED', 'POS_HUB_CLOUD_URL or POS_HUB_CLOUD_TOKEN is missing'],
    ['422', 'ITEM_COST_ALLOCATION_FAILED', 'The item-cost period is invalid'],
    ['502', 'HUB_CLOUD_SYNC_FAILED', 'The LAN hub could not upload operations to the cloud'],
];

const REQUEST_BODY_OVERRIDES = [
    'api/v1/offline/sync' => [
        'branch_id' => '{{branch_id}}',
        'device_id' => '{{offline_device_id}}',
        'operations' => [
            [
                'operation_id' => '{{offline_operation_id}}',
                'type' => 'order.created',
                'occurred_at' => '2026-10-15T14:00:00Z',
                'payload' => [
                    'client_order_id' => '{{offline_client_order_id}}',
                    'channel' => 'dine_in',
                    'fulfillment_type' => 'takeaway',
                    'items' => [
                        [
                            'menu_item_id' => '{{menu_item_id}}',
                            'quantity' => 2,
                            'notes' => 'Created while offline',
                        ],
                    ],
                ],
            ],
        ],
    ],
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

const ACTION_BODY_OVERRIDES = [
    'App\\Modules\\Delivery\\Riders\\Http\\Controllers\\RiderController@bulkAssign' => [
        'rider_id' => '{{rider_id}}',
        'order_ids' => ['{{order_id}}'],
    ],
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@store' => [
        'from_branch_id' => '{{source_branch_id}}',
        'to_branch_id' => '{{destination_branch_id}}',
        'items' => [
            ['ingredient_id' => '{{ingredient_id}}', 'quantity' => 5],
        ],
        'notes' => 'Replenishment request',
    ],
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@send' => [
        'items' => [
            ['ingredient_id' => '{{ingredient_id}}', 'quantity' => 5],
        ],
    ],
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@receive' => [
        'items' => [
            ['ingredient_id' => '{{ingredient_id}}', 'quantity' => 5],
        ],
    ],
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@receive' => [
        'items' => [
            [
                'purchase_order_item_id' => '{{purchase_order_item_id}}',
                'received_quantity' => 5,
                'unit_cost' => 12.5,
            ],
        ],
    ],
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@open' => [
        'branch_id' => '{{branch_id}}',
        'notes' => 'Morning open',
    ],
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@close' => [
        'notes' => 'End of day',
    ],
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@waste' => [
        'ingredient_id' => '{{ingredient_id}}',
        'quantity' => 1.5,
        'reason' => 'Spoilage',
    ],
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@returnStock' => [
        'to_branch_id' => '{{destination_branch_id}}',
        'return_remaining' => true,
        'notes' => 'Return leftover kitchen stock',
    ],
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineHubController@store' => [
        'branch_id' => '{{branch_id}}',
        'name' => 'Dining room hub',
    ],
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineHubController@heartbeat' => [
        'lan_url' => 'http://192.168.1.20:7744',
    ],
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@storeSnapshot' => [
        'snapshot' => [
            'schema_version' => 2,
            'menu_items' => [
                ['id' => '{{menu_item_id}}', 'name_ar' => 'كشري', 'price' => 50],
            ],
        ],
    ],
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@markItemReady' => [
        'client_order_id' => '{{offline_client_order_id}}',
        'device_id' => '{{offline_device_id}}',
        'operation_id' => '{{offline_operation_id}}',
    ],
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@publish' => [
        'device_id' => '{{offline_device_id}}',
        'role' => 'pos',
        'operations' => [
            [
                'operation_id' => '{{offline_operation_id}}',
                'type' => 'order.created',
                'occurred_at' => '2026-10-15T14:00:00Z',
                'payload' => [
                    'client_order_id' => '{{offline_client_order_id}}',
                    'channel' => 'dine_in',
                    'items' => [
                        [
                            'client_item_id' => '{{offline_client_item_id}}',
                            'menu_item_id' => '{{menu_item_id}}',
                            'quantity' => 1,
                            'item_name_ar' => 'كشري',
                        ],
                    ],
                ],
            ],
        ],
    ],
];

const DESCRIPTION_OVERRIDES = [
    'App\\Modules\\Tenant\\Http\\Controllers\\OnboardingController@register' => 'Self-service restaurant onboarding. Creates tenant, owner account, and default branch. Subdomain is optional — auto-generated when omitted.',
    'App\\Modules\\Auth\\Http\\Controllers\\AuthController@deviceLogin' => 'Branch-scoped PIN login for waiters and cashiers. Issues a 7-day token so a POS tablet can keep working through overnight internet outages. Requires tenant resolution via header or subdomain host.',
    'App\\Modules\\Auth\\Http\\Controllers\\AuthController@kitchenLogin' => 'Kitchen display device login using a shared branch secret.',
    'App\\Modules\\Delivery\\Riders\\Http\\Controllers\\RiderController@myDeliveries' => 'Active assigned deliveries (assigned, picked_up, en_route). Riders see their own jobs. Staff see all in-progress deliveries; optional rider_id and branch_id filters.',
    'App\\Modules\\Delivery\\Riders\\Http\\Controllers\\RiderController@unassigned' => 'Dispatcher queue of delivery orders that are not yet assigned to a rider (pending, no rider_id). Optional branch_id filter.',
    'App\\Modules\\Delivery\\Riders\\Http\\Controllers\\RiderController@bulkAssign' => 'Atomically assign up to 100 pending delivery orders to one active rider. The rider and every order must belong to the same branch; if any order is unavailable, none are assigned.',
    'App\\Modules\\Delivery\\Riders\\Http\\Controllers\\RiderController@orderSummary' => 'List every active rider with all non-cancelled assigned orders, plus unpaid order count, unpaid amount, and unpaid order details. Unpaid excludes paid and refunded orders. Optional branch_id filter.',
    'App\\Modules\\Delivery\\QRMenu\\Http\\Controllers\\QRMenuController@show' => 'Public QR menu for a table token. No authentication required.',
    'App\\Modules\\Delivery\\QRMenu\\Http\\Controllers\\QRMenuController@placeOrder' => 'Place a dine-in or takeaway order from the public QR menu.',
    'App\\Modules\\POS\\Orders\\Http\\Controllers\\OrderController@store' => 'Create a new order with line items (dine-in, delivery, aggregator, etc.). Sending customer_address_id or district_id fills delivery_fee from the district unless delivery_fee is sent.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineSyncController@bootstrap' => 'Download the branch snapshot required by an offline POS: pricing, menu, packages, offers, tables, districts, staff, printers, open orders, current shift, business day, and LAN hub discovery URLs. Store the returned cursor for later incremental downloads. The hub token is never included.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineSyncController@changes' => 'Download catalog and order records changed after the given cursor. Call this after a successful sync so the device picks up updates from other terminals.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineSyncController@sync' => 'Upload up to 100 queued offline operations. Supported types: order.created, order.item_added, order.item_updated, order.item_removed, order.status_changed, order.item_ready, and payment.recorded (cash only). Reuse operation_id and client UUIDs when retrying. Business-rule failures return disposition rejected while remaining operations continue.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineHubController@show' => 'Return the registered LAN hub for a branch, including the last advertised LAN URL. The plaintext token is never returned after create.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineHubController@store' => 'Register or rotate the branch LAN hub. The plaintext X-Hub-Token is returned only once; store it on the branch mini-PC as POS_HUB_TOKEN.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineHubController@heartbeat' => 'Advertise the hub LAN URL while online so POS and kitchen tablets can discover it from bootstrap.lan_hub.urls.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@health' => 'Unauthenticated discovery ping for POS and kitchen tablets on the restaurant LAN. No Sanctum token and no internet required.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@snapshot' => 'Read the catalog snapshot cached on the LAN hub so tablets can start a shift without the cloud.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@storeSnapshot' => 'POS uploads the latest GET /offline/bootstrap payload to the hub while internet is available.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@publish' => 'POS or kitchen appends idempotent operations to the LAN event log. Kitchen displays poll GET /hub/events?after={id}.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@events' => 'Poll new LAN events after a cursor. Kitchen uses this instead of Reverb when the internet is down.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@kitchenQueue' => 'Projected kitchen tickets from the local event log. Display local_number, never client UUIDs, to staff.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@markItemReady' => 'Kitchen marks a line done. Writes order.item_ready onto the LAN log so POS updates immediately.',
    'App\\Modules\\POS\\Offline\\Http\\Controllers\\LocalHubController@cloudSync' => 'Upload pending hub operations to POST /offline/sync when the internet returns. Same as php artisan pos:hub:sync.',
    'App\\Modules\\POS\\Billing\\Http\\Controllers\\PaymentController@settle' => 'Settle payment for an open order (cash, card, Vodafone Cash, split, etc.).',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\IngredientController@index' => 'Master ingredients (one row per SKU). Includes each branch stock row, total_stock, and branch_count.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\IngredientController@store' => 'Create a master ingredient. Pass branch_id to also open stock at that branch.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\InventoryStockController@index' => 'Current quantity per branch. Filter by branch_id or ingredient_id.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\InventoryStockController@store' => 'Open existing master ingredient stock at a branch.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@index' => 'List issue vouchers visible to the authenticated branch. Filter by branch and status: pending, preparing, in_transit, completed, or cancelled.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@store' => 'Restaurant creates an issue voucher asking a warehouse or another branch for ingredients. Status becomes pending. Stock is unchanged.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@show' => 'Get one issue voucher with requested, sent, and received quantities plus the users and timestamps for each lifecycle step.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@prepare' => 'Warehouse accepts a pending voucher and starts preparing the goods. Status becomes preparing. Stock is unchanged.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@send' => 'Warehouse dispatches a prepared voucher back to the restaurant. Deducts sent quantities from warehouse stock and changes status to in_transit. Omit items to send all requested quantities.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@receive' => 'Restaurant accepts an in-transit shipment. Adds received quantities to restaurant stock and changes status to completed. Omit items to receive all sent quantities.',
    'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@cancel' => 'Cancel a pending or preparing voucher from either side. After dispatch, only the restaurant can reject the shipment; warehouse stock is restored.',
    'App\\Modules\\Tenant\\Http\\Controllers\\TenantSettingsController@verifyDomain' => 'Verify custom domain DNS configuration for white-label access.',
    'App\\Modules\\Tenant\\Http\\Controllers\\TenantSettingsController@uploadLogo' => 'Upload the restaurant logo shown in tenant settings and on the public QR menu.',
    'App\\Modules\\Tenant\\Http\\Controllers\\TenantSettingsController@deleteLogo' => 'Remove the restaurant logo.',
    'App\\Modules\\Tenant\\Districts\\Http\\Controllers\\DistrictController@index' => 'List delivery districts and their fees for a branch. Pass branch_id (defaults to the authenticated user branch). POS uses this to show the fee when a district is selected.',
    'App\\Modules\\Tenant\\Districts\\Http\\Controllers\\DistrictController@store' => 'Create a delivery district with a fee for a specific branch. Owners and managers only. The same district name can exist on another branch with a different fee.',
    'App\\Modules\\Delivery\\Customers\\Http\\Controllers\\CustomerAddressController@index' => 'List a customer\'s saved addresses. Each address includes its district and delivery_fee.',
    'App\\Modules\\Delivery\\Customers\\Http\\Controllers\\CustomerAddressController@store' => 'Add an address for a customer. The district fee is returned for POS to display and can be overridden on the order.',
    'App\\Modules\\Tenant\\Staff\\Http\\Controllers\\StaffShiftController@clockIn' => 'Clock in staff member for the current shift. Attaches the shift to the open business day, or opens one automatically.',
    'App\\Modules\\Tenant\\Staff\\Http\\Controllers\\StaffShiftController@clockOut' => 'Clock out staff member and close the active shift.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@index' => 'List recent business days. Filter by branch, open/closed status, or business date.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@current' => 'Get the currently open business day for a branch. Pass branch_id; cashiers default to their assigned branch.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@show' => 'Get one business day, including opening stock and the frozen closing summary after close.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@open' => 'Open a trading day for a branch. Captures opening ingredient stock and resets daily order numbers to 1. Only one open day is allowed per branch.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@close' => 'Close the trading day after every shift is clocked out and every order is paid, refunded, or cancelled. Freezes the sales, payment, shift, and inventory report.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@report' => 'Live day report while open: orders, payments by method, sold items, shifts, remaining ingredients, waste, and stock returned to main inventory. After close this returns the frozen summary.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@remainingStock' => 'List remaining ingredient quantities for the business day. After close this returns the frozen remaining snapshot.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@waste' => 'Record wasted ingredients against the open business day and deduct them from branch stock.',
    'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@returnStock' => 'Send leftover kitchen stock back to main inventory. Pass items, or return_remaining=true to send every remaining quantity. Requires a warehouse/HQ branch and the multi-branch plan.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@store' => 'Create a draft purchase order for a supplier.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@submit' => 'Submit purchase order to supplier for fulfillment.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@print' => 'Return base64-encoded ESC/POS bytes for printing the purchase list before buying.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@receive' => 'Check a submitted purchase order by recording every actual received quantity and unit cost. Stock is not changed yet.',
    'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@complete' => 'Complete a checked purchase order and add its verified received quantities to inventory.',
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
                HasPaginationRules::class,
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
                    if (is_subclass_of($paramClass, FormRequest::class)) {
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
function collectEndpoints(Router $router): array
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

    $hasHubToken = str_contains($joined, 'AuthenticatePosHub') || str_contains($joined, 'hub.token');
    if ($hasHubToken) {
        return [
            'label' => 'X-Hub-Token',
            'type' => 'hub_token',
            'headers' => ['X-Hub-Token' => '{{offline_hub_token}}'],
        ];
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
        'delivery_fee' => 15.5,
        'district_id' => 1,
        'customer_address_id' => 1,
        'tax_rate' => 14,
        'tax_rate_applies_to' => ['dine_in', 'takeaway', 'delivery'],
        'service_charge_rate' => 12,
        'service_charge_applies_to' => ['dine_in'],
        'cursor' => '2026-10-15T14:00:00Z',
        'occurred_at' => '2026-10-15T14:00:00Z',
        'after' => 0,
        'lan_url' => 'http://192.168.1.20:7744',
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
        'label' => 'المنزل',
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
            'district_id' => '{{district_id}}',
            'customer_address_id' => '{{customer_address_id}}',
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

function exampleBodyForEndpoint(array $endpoint, array $rules): mixed
{
    return ACTION_BODY_OVERRIDES[$endpoint['action_key']]
        ?? REQUEST_BODY_OVERRIDES[$endpoint['uri']]
        ?? buildExamplePayload($rules);
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

- `POST /auth/device/login` — PIN + branch, requires tenant header. Token lasts 7 days.
- `POST /auth/device/kitchen` — kitchen display secret, requires tenant header

### Platform admin

- `POST /admin/auth/login` → use `Authorization: Bearer {admin_token}`
- No tenant header required

### Email verification

Most tenant routes require verified email (`verified.email` middleware). Verify via `GET /auth/verify-email` link or resend with `POST /auth/email/verification-notification`.

---

## Offline POS Synchronization

The frontend can cache a branch snapshot and replay POS operations after an internet outage.

1. While online, call `GET /offline/bootstrap?branch_id={id}` and replace the local snapshot. Persist `cursor`.
2. Generate and persist one UUID for the device, each operation, each client order, each client item, and each cash payment.
3. Store offline operations in a durable local outbox, in the order they happened.
4. When online, send up to 100 operations to `POST /offline/sync`.
5. Remove only `applied` and `duplicate` operations from the outbox. Keep `rejected` operations for user review or correction.
6. Retry network failures with the exact same `operation_id` and client UUIDs.
7. Call `GET /offline/changes?branch_id={id}&cursor={cursor}` and replace the local cursor with the new one.

The synchronization response is HTTP 200 when the batch is structurally valid. Each result has its own `disposition`: `applied`, `duplicate`, or `rejected`.

Supported operations: `order.created`, `order.item_added`, `order.item_updated`, `order.item_removed`, `order.status_changed`, `order.item_ready`, and `payment.recorded` (cash only). Keep the sold `unit_price` on created/added items; the server preserves it and may return a `PRICE_CHANGED` warning. PIN device tokens last 7 days. Card payments and refunds are not offline.

Customers are not part of the snapshot. For offline delivery, send `customer_phone`, `customer_name`, `delivery_address`, and optional `district_id` on `order.created`. The server finds or creates the customer by phone. Address-only delivery is allowed (no customer record).

---

## Kitchen / POS on LAN (no internet)

POS and kitchen tablets talk through a branch mini-PC running this API with `POS_HUB_ENABLED=true`. Reverb is not used.

1. While online, `POST /offline/hubs` and store the returned token as `POS_HUB_TOKEN` on the mini-PC. The token is shown only once.
2. Start the hub: `php artisan pos:hub` (listens on port `7744` by default).
3. POS calls `GET /offline/bootstrap` and tries `data.lan_hub.urls` plus `GET /hub/health` until one responds.
4. POS uploads the snapshot with `POST /hub/snapshot` while online, then publishes every local operation to `POST /hub/events`.
5. Kitchen polls `GET /hub/kitchen/queue` and `GET /hub/events?after={id}`. Marking a line done uses `PATCH /hub/kitchen/items/{clientItemId}/done`.
6. Staff see `local_number` / `daily_number`. Client UUIDs stay internal.
7. When internet returns, `POST /hub/cloud-sync` or `php artisan pos:hub:sync` uploads the same operations to `POST /offline/sync`.

Hub routes live under `/api/v1/hub/*`, outside tenant and Sanctum middleware. Kitchen and POS send `X-Hub-Token`, not a Bearer token.

---

## Stock Transfer Lifecycle

An issue voucher (اذن صرف) moves ingredients from a warehouse or source branch to a restaurant:

1. Restaurant creates the voucher: `POST /inventory/transfers` → `pending`. Stock is unchanged.
2. Warehouse accepts and prepares it: `POST /inventory/transfers/{stockTransfer}/prepare` → `preparing`. Stock is unchanged.
3. Warehouse dispatches the goods: `POST /inventory/transfers/{stockTransfer}/send` → `in_transit`. Warehouse stock is deducted.
4. Restaurant accepts: `POST /inventory/transfers/{stockTransfer}/receive` → `completed`. Restaurant stock is increased.
5. Restaurant (or warehouse, before dispatch) may cancel: `POST /inventory/transfers/{stockTransfer}/cancel`. After dispatch, only the restaurant can reject; sent quantities return to the warehouse.

Send and receive bodies are optional. Omit `items` to accept the full previous-step quantities, or provide lower quantities for partial fulfillment or a receiving shortage. A completed voucher cannot be applied twice.

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

            $exampleBody = exampleBodyForEndpoint($endpoint, $rules['body']);
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
    if (str_starts_with($uri, 'api/v1/cash-movements')) {
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
    if (str_starts_with($uri, 'api/v1/permissions') || str_starts_with($uri, 'api/v1/roles')) {
        return ['02 · Tenant Setup', '2.8 Roles & Permissions'];
    }

    if (str_starts_with($uri, 'api/v1/menu/categories')) {
        return ['03 · Menu & Floor Setup', '3.1 Menu Categories'];
    }
    if (str_starts_with($uri, 'api/v1/menu/packages')) {
        return ['03 · Menu & Floor Setup', '3.2b Menu Packages'];
    }
    if (str_starts_with($uri, 'api/v1/menu/items') && ! str_contains($uri, '/recipe') && ! str_contains($uri, '/cost')) {
        return ['03 · Menu & Floor Setup', '3.2 Menu Items'];
    }
    if (str_starts_with($uri, 'api/v1/tables')) {
        return ['03 · Menu & Floor Setup', '3.3 Floor Tables'];
    }
    if (str_starts_with($uri, 'api/v1/print/')) {
        return ['03 · Menu & Floor Setup', '3.4 Printer & Kitchen Routing Setup'];
    }

    if (str_starts_with($uri, 'api/v1/business-days')) {
        return ['04 · Daily Operations (POS)', '4.0 Business Days'];
    }

    if (str_starts_with($uri, 'api/v1/hub')) {
        return ['04 · Daily Operations (POS)', '4.9 LAN Hub'];
    }

    if (str_starts_with($uri, 'api/v1/offline')) {
        return ['04 · Daily Operations (POS)', '4.8 Offline POS'];
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
    if (str_contains($uri, '/print/')) {
        return ['04 · Daily Operations (POS)', '4.5 Print Tickets'];
    }
    if (str_starts_with($uri, 'api/v1/orders')) {
        return ['04 · Daily Operations (POS)', '4.1 Orders'];
    }
    if (str_starts_with($uri, 'api/v1/offers')) {
        return ['04 · Daily Operations (POS)', '4.7 Offers'];
    }
    if (str_starts_with($uri, 'api/v1/kitchen')) {
        return ['04 · Daily Operations (POS)', '4.3 Kitchen Display'];
    }
    if (str_starts_with($uri, 'api/v1/invoices')) {
        return ['04 · Daily Operations (POS)', '4.6 ETA Invoices'];
    }

    if (str_starts_with($uri, 'api/v1/expense-categories')) {
        return ['05 · Finance & Reports', '5.1 Expense Categories'];
    }
    if (str_starts_with($uri, 'api/v1/expenses')) {
        return ['05 · Finance & Reports', '5.2 Expenses & Summary'];
    }
    if (str_starts_with($uri, 'api/v1/reports/daily')
        || str_starts_with($uri, 'api/v1/reports/cash-summary')
        || str_starts_with($uri, 'api/v1/reports/top-items')) {
        return ['05 · Finance & Reports', '5.3 Daily & Sales Reports'];
    }
    if (str_starts_with($uri, 'api/v1/reports/branches')) {
        return ['05 · Finance & Reports', '5.4 Branch Comparison'];
    }
    if (str_starts_with($uri, 'api/v1/reports/ai-summary')) {
        return ['08 · Intelligence', '8.4 AI Weekly Summary'];
    }

    if (str_starts_with($uri, 'api/v1/customers')) {
        return ['06 · Delivery & QR', '6.1 Customers'];
    }
    if (str_starts_with($uri, 'api/v1/riders') || str_starts_with($uri, 'api/v1/deliveries')) {
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
    if (str_starts_with($uri, 'api/v1/inventory/ingredients')
        || str_starts_with($uri, 'api/v1/inventory/low-stock')
        || str_starts_with($uri, 'api/v1/inventory/')) {
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
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@index' => 'List issue vouchers',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@store' => 'Request issue voucher',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@show' => 'Get issue voucher',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@prepare' => 'Warehouse prepares issue voucher',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@send' => 'Warehouse dispatches issue voucher',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@receive' => 'Restaurant accepts goods',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@cancel' => 'Cancel or reject issue voucher',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@index' => 'List business days',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@current' => 'Get current business day',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@show' => 'Get business day',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@open' => 'Open business day',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@close' => 'Close business day',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@report' => 'Business day report',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@remainingStock' => 'Remaining ingredients',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@waste' => 'Record waste',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@returnStock' => 'Return leftover stock to main inventory',
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
 * @param  list<array<string, mixed>>  $endpoints
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
 * @param  array<string, array{items: list<array<string, mixed>>, children: array<string, mixed>}>  $tree
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
        str_contains($folderName, 'Roles') => 'Permission catalog plus restaurant roles. Customizing a system role applies only to this restaurant. The owner role stays locked. Assign a custom role from the staff endpoints.',
        str_contains($folderName, 'Business Days') => 'Open/close the trading day, reset daily order numbers, inspect remaining ingredients, record waste, and return leftover stock to main inventory.',
        str_contains($folderName, 'Shifts') => 'Clock in/out, inspect transaction-level reconciliation, record paid-in/paid-out/safe-drop movements, and reverse mistakes.',
        str_contains($folderName, 'Expense Categories') => 'Owner/manager-defined categories used to classify restaurant operating expenses. Set allocates_to_item_cost to include approved bills in plate cost.',
        str_contains($folderName, 'Expenses') => 'Submit, approve, void, filter, and summarize expenses. Approved cash expenses automatically reduce the linked shift drawer. GET /expenses/item-cost spreads flagged categories across paid items sold in the period.',
        str_contains($folderName, 'Menu Categories') => 'Setup menu structure before adding items.',
        str_contains($folderName, 'Recipes') => 'Recipe lines are food cost. Approved expenses from categories with allocates_to_item_cost are divided evenly across paid items and returned as overhead_cost and full_cost.',
        str_contains($folderName, 'Ingredients') => 'Ingredients = shared master data (SKU, name, unit). Stock = quantity at one branch. Recipes and transfers use the master ingredient id.',
        str_contains($folderName, 'Stock Transfers') => 'Issue voucher: restaurant requests → warehouse prepares → warehouse dispatches → restaurant accepts or rejects.',
        str_contains($folderName, 'Menu Packages') => 'Combo meals: fixed items plus choice slots. Explodes into kitchen lines on order.',
        str_contains($folderName, 'Offers') => 'Percentage or fixed discounts with optional coupon, schedule, and channel rules.',
        str_contains($folderName, 'Menu Items') => 'Add dishes with Arabic names, prices, photos.',
        str_contains($folderName, 'Floor Tables') => 'Table layout for dine-in orders and table QR codes.',
        str_contains($folderName, 'Printer & Kitchen') => 'Configure logical printers, optional kitchen stations, direct/category/item routes, and branch printing mode.',
        str_contains($folderName, '4.1 Orders') => 'Core POS flow: list → create → update status.',
        str_contains($folderName, 'Offline POS') => 'Download a branch snapshot, queue operations locally, and replay the same operation UUIDs until the server accepts them. Register the branch LAN hub here.',
        str_contains($folderName, 'LAN Hub') => 'Local restaurant-network endpoints. POS and kitchen use X-Hub-Token against the branch mini-PC; no internet and no Sanctum token.',
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
- `source_branch_id`, `destination_branch_id` — branches participating in a transfer
- `stock_transfer_id` — set after creating a transfer request
- `kitchen_device_secret` — from onboarding response (shown once)
- `offline_device_id`, `offline_operation_id`, `offline_client_order_id`, `offline_client_item_id` — stable UUIDs used to test idempotent offline retries
- `hub_base_url`, `offline_hub_token` — LAN hub on the branch mini-PC (`http://pos-hub.local:7744/api/v1`)

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
            ['key' => 'source_branch_id', 'value' => '1'],
            ['key' => 'destination_branch_id', 'value' => '2'],
            ['key' => 'order_id', 'value' => '1'],
            ['key' => 'rider_id', 'value' => '1'],
            ['key' => 'menu_item_id', 'value' => '1'],
            ['key' => 'customer_id', 'value' => '1'],
            ['key' => 'customer_address_id', 'value' => '1'],
            ['key' => 'district_id', 'value' => '1'],
            ['key' => 'supplier_id', 'value' => '1'],
            ['key' => 'ingredient_id', 'value' => '1'],
            ['key' => 'stock_id', 'value' => '1'],
            ['key' => 'purchase_order_id', 'value' => '1'],
            ['key' => 'purchase_order_item_id', 'value' => '1'],
            ['key' => 'stock_transfer_id', 'value' => '1'],
            ['key' => 'qr_token', 'value' => 'table-token-example'],
            ['key' => 'staff_id', 'value' => '1'],
            ['key' => 'table_id', 'value' => '1'],
            ['key' => 'category_id', 'value' => '1'],
            ['key' => 'package_id', 'value' => '1'],
            ['key' => 'offer_id', 'value' => '1'],
            ['key' => 'shift_id', 'value' => '1'],
            ['key' => 'cash_movement_id', 'value' => '1'],
            ['key' => 'expense_category_id', 'value' => '1'],
            ['key' => 'expense_id', 'value' => '1'],
            ['key' => 'printer_id', 'value' => '1'],
            ['key' => 'station_id', 'value' => '1'],
            ['key' => 'offline_device_id', 'value' => '11111111-1111-4111-8111-111111111111'],
            ['key' => 'offline_operation_id', 'value' => '22222222-2222-4222-8222-222222222222'],
            ['key' => 'offline_client_order_id', 'value' => '33333333-3333-4333-8333-333333333333'],
            ['key' => 'offline_client_item_id', 'value' => '44444444-4444-4444-8444-444444444444'],
            ['key' => 'offline_hub_id', 'value' => '1'],
            ['key' => 'offline_hub_token', 'value' => ''],
            ['key' => 'hub_base_url', 'value' => 'http://pos-hub.local:7744/api/v1'],
            ['key' => 'business_day_id', 'value' => '1'],
        ],
        'item' => $items,
    ];
}

function postmanRequestItem(array $endpoint, array $requestMap, array $allRules): array
{
    $rules = rulesForEndpoint($endpoint, $requestMap, $allRules);
    $exampleBody = exampleBodyForEndpoint($endpoint, $rules['body']);

    $headers = [
        ['key' => 'Accept', 'value' => 'application/json'],
        ['key' => 'Content-Type', 'value' => 'application/json'],
    ];

    foreach ($endpoint['auth']['headers'] as $key => $value) {
        $headers[] = ['key' => $key, 'value' => $value];
    }

    $relativePath = preg_replace('#^api/v1/#', '', $endpoint['uri']) ?? $endpoint['uri'];
    $baseVar = str_starts_with($endpoint['uri'], 'api/v1/hub') ? '{{hub_base_url}}' : '{{base_url}}';
    $url = $baseVar.'/'.$relativePath;
    $url = str_replace(
        ['{tenant}', '{branch}', '{order}', '{item}', '{customer}', '{supplier}', '{purchaseOrder}', '{stockCount}', '{stockTransfer}', '{stock}', '{invoice}', '{shift}', '{staff}', '{category}', '{table}', '{ingredient}', '{token}', '{movement}', '{expense}', '{printer}', '{station}', '{district}', '{address}', '{package}', '{offer}', '{businessDay}', '{hub}', '{clientItemId}'],
        ['{{tenant_id}}', '{{branch_id}}', '{{order_id}}', '{{menu_item_id}}', '{{customer_id}}', '{{supplier_id}}', '{{purchase_order_id}}', '{{stock_count_id}}', '{{stock_transfer_id}}', '{{stock_id}}', '{{invoice_id}}', '{{shift_id}}', '{{staff_id}}', '{{category_id}}', '{{table_id}}', '{{ingredient_id}}', '{{qr_token}}', '{{cash_movement_id}}', '{{expense_id}}', '{{printer_id}}', '{{station_id}}', '{{district_id}}', '{{customer_address_id}}', '{{package_id}}', '{{offer_id}}', '{{business_day_id}}', '{{offline_hub_id}}', '{{offline_client_item_id}}'],
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
            'host' => [str_starts_with($endpoint['uri'], 'api/v1/hub') ? '{{hub_base_url}}' : '{{base_url}}'],
            'path' => $pathSegments,
            'query' => $query,
        ],
        'description' => endpointDescription($endpoint)."\n\nAuth: ".$endpoint['auth']['label'],
    ];

    if ($rules['body'] !== [] || isset(REQUEST_BODY_OVERRIDES[$endpoint['uri']]) || isset(ACTION_BODY_OVERRIDES[$endpoint['action_key']])) {
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
                    'const json = pm.response.json();',
                    'if (json.data && json.data.token) {',
                    "    pm.collectionVariables.set('tenant_token', json.data.token);",
                    '}',
                    'if (json.data?.user?.tenant_id) {',
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
                    'const json = pm.response.json();',
                    'if (json.data?.token) {',
                    "    pm.collectionVariables.set('tenant_token', json.data.token);",
                    '}',
                    'if (json.data?.tenant?.subdomain) {',
                    "    pm.collectionVariables.set('tenant_subdomain', json.data.tenant.subdomain);",
                    '}',
                    'if (json.data?.branch?.id) {',
                    "    pm.collectionVariables.set('branch_id', json.data.branch.id);",
                    '}',
                    'if (json.data?.kitchen_device_secret) {',
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
                    'const json = pm.response.json();',
                    'if (json.data && json.data.token) {',
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
                    'const json = pm.response.json();',
                    'if (json.data && json.data.id) {',
                    "    pm.collectionVariables.set('order_id', json.data.id);",
                    '}',
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineHubController@store') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const json = pm.response.json();',
                    'if (json.data?.id) {',
                    "    pm.collectionVariables.set('offline_hub_id', json.data.id);",
                    '}',
                    'if (json.data?.token) {',
                    "    pm.collectionVariables.set('offline_hub_token', json.data.token);",
                    '}',
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\POS\\Offline\\Http\\Controllers\\OfflineSyncController@sync') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const json = pm.response.json();',
                    'const result = json.data?.results?.[0];',
                    'if (result?.server_order_id) {',
                    "    pm.collectionVariables.set('order_id', result.server_order_id);",
                    '}',
                    "pm.test('operation was applied or detected as duplicate', function () {",
                    "    pm.expect(['applied', 'duplicate']).to.include(result?.disposition);",
                    '});',
                ],
            ],
        ];
    }

    $createdIdVariables = [
        'App\\Modules\\Tenant\\Staff\\Http\\Controllers\\StaffShiftController@clockIn' => 'shift_id',
        'App\\Modules\\Tenant\\BusinessDays\\Http\\Controllers\\BusinessDayController@open' => 'business_day_id',
        'App\\Modules\\Tenant\\Finance\\Http\\Controllers\\CashMovementController@store' => 'cash_movement_id',
        'App\\Modules\\Tenant\\Finance\\Http\\Controllers\\ExpenseCategoryController@store' => 'expense_category_id',
        'App\\Modules\\Tenant\\Finance\\Http\\Controllers\\ExpenseController@store' => 'expense_id',
        'App\\Modules\\POS\\Print\\Http\\Controllers\\PrinterController@store' => 'printer_id',
        'App\\Modules\\POS\\Print\\Http\\Controllers\\KitchenStationController@store' => 'station_id',
        'App\\Modules\\Inventory\\Stock\\Http\\Controllers\\StockTransferController@store' => 'stock_transfer_id',
        'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\SupplierController@store' => 'supplier_id',
        'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@store' => 'purchase_order_id',
    ];

    if (isset($createdIdVariables[$endpoint['action_key']])) {
        $variable = $createdIdVariables[$endpoint['action_key']];
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const json = pm.response.json();',
                    'if (json.data?.id) {',
                    "    pm.collectionVariables.set('{$variable}', json.data.id);",
                    '}',
                ],
            ],
        ];
    }

    if ($endpoint['action_key'] === 'App\\Modules\\Inventory\\Suppliers\\Http\\Controllers\\PurchaseOrderController@store') {
        $events[] = [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'const json = pm.response.json();',
                    'if (json.data?.items?.[0]?.id) {',
                    "    pm.collectionVariables.set('purchase_order_item_id', json.data.items[0].id);",
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
        '05 · Offline POS (snapshot → sync → retry)' => [
            $find('GET', 'offline/bootstrap'),
            $find('POST', 'offline/sync'),
            $find('POST', 'offline/sync'),
            $find('GET', 'offline/changes'),
        ],
        '05b · LAN hub (POS → kitchen → cloud sync)' => [
            $find('POST', 'offline/hubs'),
            $find('POST', 'offline/hubs/{hub}/heartbeat'),
            $find('GET', 'hub/health'),
            $find('POST', 'hub/snapshot'),
            $find('POST', 'hub/events'),
            $find('GET', 'hub/kitchen/queue'),
            $find('PATCH', 'hub/kitchen/items/{clientItemId}/done'),
            $find('POST', 'hub/cloud-sync'),
        ],
        '06 · QR order (public)' => [
            $find('GET', 'qr/{token}/menu'),
            $find('POST', 'qr/{token}/orders'),
        ],
        '07 · Custom domain verify' => [
            $find('PATCH', 'settings'),
            $find('GET', 'settings/domain'),
            $find('POST', 'settings/domain/verify'),
        ],
        '08 · Staff shift' => [
            $find('POST', 'staff/shifts/clock-in'),
            $find('POST', 'staff/shifts/clock-out'),
        ],
        '08b · Business day (open → remaining → waste → return → close)' => [
            $find('POST', 'business-days/open'),
            $find('GET', 'business-days/current'),
            $find('GET', 'business-days/{businessDay}/remaining-stock'),
            $find('POST', 'business-days/{businessDay}/waste'),
            $find('POST', 'business-days/{businessDay}/return-stock'),
            $find('GET', 'business-days/{businessDay}/report'),
            $find('POST', 'business-days/{businessDay}/close'),
        ],
        '09 · Inventory PO flow (create → print → check → complete)' => [
            $find('POST', 'inventory/suppliers'),
            $find('POST', 'inventory/purchase-orders'),
            $find('GET', 'inventory/purchase-orders/{purchaseOrder}/print'),
            $find('POST', 'inventory/purchase-orders/{purchaseOrder}/submit'),
            $find('POST', 'inventory/purchase-orders/{purchaseOrder}/receive'),
            $find('POST', 'inventory/purchase-orders/{purchaseOrder}/complete'),
        ],
        '10 · Inventory transfer (request → prepare → send → receive)' => [
            $find('POST', 'inventory/transfers'),
            $find('POST', 'inventory/transfers/{stockTransfer}/prepare'),
            $find('POST', 'inventory/transfers/{stockTransfer}/send'),
            $find('POST', 'inventory/transfers/{stockTransfer}/receive'),
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
        $offlineSyncAttempt = 0;
        foreach (array_filter($scenarioEndpoints) as $ep) {
            $item = postmanRequestItem($ep, $requestMap, $allRules);
            $pathKey = preg_replace('#^api/v1/#', '', $ep['uri']) ?? $ep['uri'];
            if ($pathKey === 'offline/sync') {
                $offlineSyncAttempt++;
                $item['name'] = $offlineSyncAttempt === 1
                    ? 'POST · Upload offline order'
                    : 'POST · Retry same operation (returns duplicate)';
            }
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
            ['key' => 'source_branch_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'destination_branch_id', 'value' => '2', 'type' => 'default', 'enabled' => true],
            ['key' => 'order_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'rider_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'menu_item_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'customer_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'customer_address_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'district_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'supplier_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'ingredient_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'stock_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'purchase_order_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'purchase_order_item_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'stock_transfer_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'qr_token', 'value' => 'table-token-example', 'type' => 'default', 'enabled' => true],
            ['key' => 'staff_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'table_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'category_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'invoice_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'shift_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'tenant_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'cash_movement_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'expense_category_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'expense_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'printer_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'station_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'offline_device_id', 'value' => '11111111-1111-4111-8111-111111111111', 'type' => 'default', 'enabled' => true],
            ['key' => 'offline_operation_id', 'value' => '22222222-2222-4222-8222-222222222222', 'type' => 'default', 'enabled' => true],
            ['key' => 'offline_client_order_id', 'value' => '33333333-3333-4333-8333-333333333333', 'type' => 'default', 'enabled' => true],
            ['key' => 'offline_client_item_id', 'value' => '44444444-4444-4444-8444-444444444444', 'type' => 'default', 'enabled' => true],
            ['key' => 'offline_hub_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
            ['key' => 'offline_hub_token', 'value' => '', 'type' => 'secret', 'enabled' => true],
            ['key' => 'hub_base_url', 'value' => 'http://pos-hub.local:7744/api/v1', 'type' => 'default', 'enabled' => true],
            ['key' => 'business_day_id', 'value' => '1', 'type' => 'default', 'enabled' => true],
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
