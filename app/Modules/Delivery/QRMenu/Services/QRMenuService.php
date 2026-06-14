<?php

declare(strict_types=1);

namespace App\Modules\Delivery\QRMenu\Services;

use App\Modules\Delivery\Customers\Services\CustomerService;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Orders\Services\OrderPlacementService;
use App\Modules\POS\Tables\Models\FloorTable;
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
     * Resolve a table from its public QR token and bind the tenant context.
     */
    public function resolveTable(string $token): FloorTable
    {
        $table = FloorTable::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('qr_token', $token)
            ->where('is_active', true)
            ->with(['branch', 'tenant'])
            ->first();

        if (! $table) {
            throw new InvalidArgumentException('Invalid or expired QR code.');
        }

        app()->instance('tenant', $table->tenant);

        if (! $table->tenant->hasFeature('qr_menu')) {
            throw new RuntimeException('QR menu is not enabled for this restaurant.');
        }

        return $table;
    }

    /**
     * @return array<string, mixed>
     */
    public function menuForTable(FloorTable $table): array
    {
        $tenant = $table->tenant;

        $categories = MenuCategory::query()
            ->where('is_visible', true)
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

        return [
            'restaurant' => [
                'name' => $tenant->name,
                'locale' => $tenant->locale,
            ],
            'branch' => [
                'id' => $table->branch_id,
                'name' => $table->branch?->name,
                'name_ar' => $table->branch?->name_ar,
            ],
            'table' => [
                'id' => $table->id,
                'name' => $table->name,
                'section' => $table->section,
            ],
            'categories' => $categories,
        ];
    }

    /**
     * @param  array<int, array{menu_item_id: int, quantity: int, notes?: string|null}>  $items
     */
    public function placeOrder(
        FloorTable $table,
        array $items,
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $notes = null,
    ): Order {
        if ($table->status === 'unavailable') {
            throw new InvalidArgumentException('This table is currently unavailable.');
        }

        $customerId = null;
        if ($customerPhone) {
            $customer = $this->customers->findOrCreate(
                phone: $customerPhone,
                name: $customerName,
            );
            $customerId = $customer->id;
        }

        return $this->orders->place(
            branchId: $table->branch_id,
            channel: 'qr',
            items: $items,
            floorTableId: $table->id,
            customerId: $customerId,
            notes: $notes,
        );
    }
}
