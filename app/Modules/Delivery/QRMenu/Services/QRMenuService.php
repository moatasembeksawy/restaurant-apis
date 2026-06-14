<?php

declare(strict_types=1);

namespace App\Modules\Delivery\QRMenu\Services;

use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\Delivery\QRMenu\QRMenuContext;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\POS\Orders\Support\OrderFulfillment;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Shared\Support\Scopes\TenantScope;
use InvalidArgumentException;
use RuntimeException;

class QRMenuService
{
    public function __construct(
        private readonly OrderPlacementService $orders,
        private readonly CustomerService $customers,
    ) {}

    /**
     * Resolve a public QR token — table token (dine-in) or branch menu token (shareable).
     */
    public function resolve(string $token): QRMenuContext
    {
        $table = FloorTable::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('qr_token', $token)
            ->where('is_active', true)
            ->with(['branch', 'tenant'])
            ->first();

        if ($table) {
            $this->bindTenant($table->tenant);

            return new QRMenuContext($table->tenant, $table->branch, $table);
        }

        $branch = Branch::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('qr_menu_token', $token)
            ->where('is_active', true)
            ->with('tenant')
            ->first();

        if ($branch) {
            $this->bindTenant($branch->tenant);

            return new QRMenuContext($branch->tenant, $branch);
        }

        throw new InvalidArgumentException('Invalid or expired QR code.');
    }

    /** @deprecated Use resolve() — kept for backward compatibility in callers */
    public function resolveTable(string $token): FloorTable
    {
        $context = $this->resolve($token);

        if (! $context->table) {
            throw new InvalidArgumentException('This QR link is a branch menu, not a table code.');
        }

        return $context->table;
    }

    /** @return array<string, mixed> */
    public function menuFor(QRMenuContext $context): array
    {
        $tenant = $context->tenant;
        $branch = $context->branch;

        $categories = MenuCategory::query()
            ->where('is_visible', true)
            ->where(fn ($query) => $query
                ->whereNull('branch_id')
                ->orWhere('branch_id', $branch->id))
            ->orderBy('sort_order')
            ->with(['availableItems' => fn ($q) => $q->orderBy('sort_order')])
            ->get()
            ->map(fn (MenuCategory $cat) => [
                'id' => $cat->id,
                'name_ar' => $cat->name_ar,
                'name_en' => $cat->name_en,
                'items' => $cat->availableItems->map(fn ($item) => [
                    'id' => $item->id,
                    'name_ar' => $item->name_ar,
                    'name_en' => $item->name_en,
                    'description_ar' => $item->description_ar,
                    'price' => $item->price,
                    'photo_url' => $item->photo_url,
                    'preparation_time' => $item->preparation_time,
                ])->values()->all(),
            ])
            ->filter(fn (array $cat) => count($cat['items']) > 0)
            ->values()
            ->all();

        $table = $context->table;

        return [
            'source' => $table ? 'table' : 'branch',
            'menu_url' => $this->publicMenuUrl($table?->qr_token ?? $branch->qr_menu_token),
            'restaurant' => [
                'name' => $tenant->name,
                'locale' => $tenant->locale,
            ],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'name_ar' => $branch->name_ar,
                'address' => $branch->address,
                'phone' => $branch->phone,
            ],
            'table' => $table ? [
                'id' => $table->id,
                'name' => $table->name,
                'section' => $table->section,
            ] : null,
            'categories' => $categories,
        ];
    }

    /** @return array<string, mixed> */
    public function menuForTable(FloorTable $table): array
    {
        app()->instance('tenant', $table->tenant);

        if (! $table->tenant->hasFeature('qr_menu')) {
            throw new RuntimeException('QR menu is not enabled for this restaurant.');
        }

        return $this->menuFor(new QRMenuContext($table->tenant, $table->branch, $table));
    }

    /**
     * @param  array<int, array{menu_item_id: int, quantity: int, notes?: string|null}>  $items
     */
    public function placeOrder(
        QRMenuContext $context,
        array $items,
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $notes = null,
        ?string $tableLabel = null,
        ?string $fulfillmentType = null,
        ?string $deliveryAddress = null,
    ): Order {
        $table = $context->table;

        if ($table && $table->status === 'unavailable') {
            throw new InvalidArgumentException('This table is currently unavailable.');
        }

        if ($table) {
            $fulfillmentType = OrderFulfillment::DINE_IN;
        } elseif ($fulfillmentType === null) {
            $fulfillmentType = OrderFulfillment::TAKEAWAY;
        }

        $customerId = null;
        if ($customerPhone) {
            $customer = $this->customers->findOrCreate(
                phone: $customerPhone,
                name: $customerName,
                address: $fulfillmentType === OrderFulfillment::DELIVERY ? $deliveryAddress : null,
            );
            $customerId = $customer->id;
        }

        $orderNotes = $notes;

        if ($tableLabel && ! $table) {
            $label = trim($tableLabel);
            $orderNotes = $orderNotes
                ? "Table: {$label}\n{$orderNotes}"
                : "Table: {$label}";
        }

        return $this->orders->place(
            branchId: $context->branch->id,
            channel: 'qr',
            items: $items,
            floorTableId: $table?->id,
            customerId: $customerId,
            notes: $orderNotes,
            deliveryAddress: $deliveryAddress,
            fulfillmentType: $fulfillmentType,
        );
    }

    public function publicMenuUrl(?string $token): ?string
    {
        if (! $token) {
            return null;
        }

        return rtrim((string) config('app.url'), '/')."/api/v1/qr/{$token}/menu";
    }

    private function bindTenant(Tenant $tenant): void
    {
        app()->instance('tenant', $tenant);

        if (! $tenant->hasFeature('qr_menu')) {
            throw new RuntimeException('QR menu is not enabled for this restaurant.');
        }
    }
}
