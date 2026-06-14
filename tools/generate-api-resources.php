<?php

declare(strict_types=1);

/**
 * One-off generator for API Resource classes.
 * Run: php tools/generate-api-resources.php
 */
$basePath = dirname(__DIR__);

/** @var list<array{namespace: string, class: string, model?: string, extras?: string, extends?: string}> */
$resources = [
    // Shared model resources
    ['namespace' => 'App\\Modules\\Inventory\\Suppliers\\Http\\Resources', 'class' => 'SupplierResource', 'model' => 'App\\Modules\\Inventory\\Suppliers\\Models\\Supplier'],
    ['namespace' => 'App\\Modules\\Inventory\\Suppliers\\Http\\Resources', 'class' => 'PurchaseOrderResource', 'model' => 'App\\Modules\\Inventory\\Suppliers\\Models\\PurchaseOrder'],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Resources', 'class' => 'IngredientResource', 'model' => 'App\\Modules\\Inventory\\Stock\\Models\\Ingredient'],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Resources', 'class' => 'StockMovementResource', 'model' => 'App\\Modules\\Inventory\\Stock\\Models\\StockMovement'],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Resources', 'class' => 'StockTransferResource', 'model' => 'App\\Modules\\Inventory\\Stock\\Models\\StockTransfer'],
    ['namespace' => 'App\\Modules\\Inventory\\Stock\\Http\\Resources', 'class' => 'StockCountResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Inventory\\Recipes\\Http\\Resources', 'class' => 'RecipeCostResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Resources', 'class' => 'OrderResource', 'model' => 'App\\Modules\\POS\\Orders\\Models\\Order'],
    ['namespace' => 'App\\Modules\\POS\\Orders\\Http\\Resources', 'class' => 'OrderItemResource', 'model' => 'App\\Modules\\POS\\Orders\\Models\\OrderItem'],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Resources', 'class' => 'MenuCategoryResource', 'model' => 'App\\Modules\\POS\\Menu\\Models\\MenuCategory'],
    ['namespace' => 'App\\Modules\\POS\\Menu\\Http\\Resources', 'class' => 'MenuItemResource', 'model' => 'App\\Modules\\POS\\Menu\\Models\\MenuItem', 'extras' => "return ['photo_url' => \$this->resource->photoUrl()];"],
    ['namespace' => 'App\\Modules\\POS\\Tables\\Http\\Resources', 'class' => 'FloorTableResource', 'model' => 'App\\Modules\\POS\\Tables\\Models\\FloorTable'],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Resources', 'class' => 'PaymentResource', 'model' => 'App\\Modules\\POS\\Billing\\Models\\Payment'],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Resources', 'class' => 'InvoiceResource', 'model' => 'App\\Modules\\POS\\Billing\\Models\\Invoice'],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Resources', 'class' => 'PaymentSettlementResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Resources', 'class' => 'PaymentRefundResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\POS\\Billing\\Http\\Resources', 'class' => 'ReportResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\POS\\Print\\Http\\Resources', 'class' => 'PrintJobResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\POS\\Kitchen\\Http\\Resources', 'class' => 'KitchenQueueResource', 'model' => 'App\\Modules\\POS\\Orders\\Models\\Order'],
    ['namespace' => 'App\\Modules\\Delivery\\Customers\\Http\\Resources', 'class' => 'CustomerResource', 'model' => 'App\\Modules\\Delivery\\Customers\\Models\\Customer'],
    ['namespace' => 'App\\Modules\\Delivery\\Riders\\Http\\Resources', 'class' => 'RiderResource', 'model' => 'App\\Models\\User'],
    ['namespace' => 'App\\Modules\\Delivery\\QRMenu\\Http\\Resources', 'class' => 'QRMenuResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Delivery\\QRMenu\\Http\\Resources', 'class' => 'QRMenuOrderResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Delivery\\Aggregators\\Http\\Resources', 'class' => 'AggregatorWebhookResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Intelligence\\Marketing\\Http\\Resources', 'class' => 'MarketingCampaignResource', 'model' => 'App\\Modules\\Intelligence\\Marketing\\Models\\MarketingCampaign'],
    ['namespace' => 'App\\Modules\\Intelligence\\Marketing\\Http\\Resources', 'class' => 'MarketingSegmentResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Intelligence\\Loyalty\\Http\\Resources', 'class' => 'LoyaltyProfileResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Intelligence\\Loyalty\\Http\\Resources', 'class' => 'LoyaltyRedemptionResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Intelligence\\Analytics\\Http\\Resources', 'class' => 'AggregatorComparisonResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Intelligence\\Reports\\Http\\Resources', 'class' => 'AIReportResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Resources', 'class' => 'PlatformAdminResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Resources', 'class' => 'AdminAuthResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Resources', 'class' => 'AdminTenantResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Resources', 'class' => 'AdminDashboardResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Platform\\Http\\Resources', 'class' => 'ImpersonationResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'BranchResource', 'model' => 'App\\Modules\\Tenant\\Models\\Branch', 'extras' => "return ['qr_menu_url' => \$this->resource->qrMenuUrl()];"],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'StaffResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'TenantSettingsResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'ETASettingsResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'SubscriptionResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'OnboardingResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'OpsHealthResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'DomainStatusResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Http\\Resources', 'class' => 'AuditLogResource', 'model' => 'Spatie\\Activitylog\\Models\\Activity'],
    ['namespace' => 'App\\Modules\\Tenant\\Staff\\Http\\Resources', 'class' => 'StaffShiftResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Tenant\\Reports\\Http\\Resources', 'class' => 'BranchComparisonResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Auth\\Http\\Resources', 'class' => 'AuthTokenResource', 'extends' => 'DataResource'],
    ['namespace' => 'App\\Modules\\Auth\\Http\\Resources', 'class' => 'AuthenticatedUserResource', 'extends' => 'DataResource'],
];

foreach ($resources as $resource) {
    $namespace = $resource['namespace'];
    $class = $resource['class'];
    $extends = $resource['extends'] ?? 'ModelResource';
    $extendsFqn = 'App\\Shared\\Support\\Http\\Resources\\'.$extends;

    $relativePath = str_replace('App\\', 'app/', $namespace);
    $relativePath = str_replace('\\', '/', $relativePath);
    $dir = $basePath.'/'.$relativePath;
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $extrasMethod = '';
    if (isset($resource['extras'])) {
        $extrasMethod = <<<PHP

    /** @return array<string, mixed> */
    protected function extras(\Illuminate\Http\Request \$request): array
    {
        {$resource['extras']}
    }
PHP;
    }

    $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$extendsFqn};

class {$class} extends {$extends}
{{$extrasMethod}
}

PHP;

    file_put_contents($dir.'/'.$class.'.php', $content);
    echo "Created {$class}\n";
}

// RiderResource needs custom fields - override manually after
$riderPath = $basePath.'/app/Modules/Delivery/Riders/Http/Resources/RiderResource.php';
file_put_contents($riderPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Riders\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id,
        ];
    }
}

PHP);

echo 'Done. Created '.count($resources)." api resources.\n";
