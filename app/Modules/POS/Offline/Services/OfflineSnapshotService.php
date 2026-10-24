<?php

declare(strict_types=1);

namespace App\Modules\POS\Offline\Services;

use App\Models\User;
use App\Modules\POS\Menu\Models\MenuCategory;
use App\Modules\POS\Menu\Models\MenuItem;
use App\Modules\POS\Menu\Models\MenuItemPart;
use App\Modules\POS\Offers\Models\Offer;
use App\Modules\POS\Offline\Models\OfflineHub;
use App\Modules\POS\Offline\Support\OfflineOperations;
use App\Modules\POS\Orders\Http\Resources\OrderResource;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\POS\Packages\Models\MenuPackage;
use App\Modules\POS\Print\Models\KitchenStation;
use App\Modules\POS\Print\Models\Printer;
use App\Modules\POS\Print\Models\PrintRoute;
use App\Modules\POS\Tables\Models\FloorTable;
use App\Modules\Tenant\BusinessDays\Http\Resources\BusinessDayResource;
use App\Modules\Tenant\BusinessDays\Services\BusinessDayService;
use App\Modules\Tenant\Districts\Models\District;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;
use App\Modules\Tenant\Staff\Models\StaffShift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OfflineSnapshotService
{
    public function __construct(
        private readonly BusinessDayService $businessDays,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(Request $request, User $user, Tenant $tenant, int $branchId): array
    {
        $generatedAt = now()->toISOString();

        return array_merge(
            $this->catalog($tenant, $branchId),
            [
                'schema_version' => OfflineOperations::SCHEMA_VERSION,
                'generated_at' => $generatedAt,
                'cursor' => $generatedAt,
                'supported_operations' => OfflineOperations::ALL,
                'branch' => $this->branchPayload(Branch::query()->findOrFail($branchId), $tenant),
                'open_orders' => OrderResource::collection(
                    $this->ordersQuery($branchId)
                        ->whereIn('status', ['pending', 'active', 'cooking', 'ready'])
                        ->get(),
                )->resolve($request),
                'current_shift' => $this->currentShift($user),
                'business_day' => $this->businessDay($request, $branchId),
                'lan_hub' => $this->lanHub($branchId),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(
        Request $request,
        Tenant $tenant,
        int $branchId,
        CarbonInterface $since,
    ): array {
        $generatedAt = now()->toISOString();

        return array_merge(
            $this->catalog($tenant, $branchId, $since),
            [
                'schema_version' => OfflineOperations::SCHEMA_VERSION,
                'generated_at' => $generatedAt,
                'cursor' => $generatedAt,
                'since' => $since->toISOString(),
                'supported_operations' => OfflineOperations::ALL,
                'orders' => OrderResource::collection(
                    $this->changedSince($this->ordersQuery($branchId), $since)->get(),
                )->resolve($request),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(Tenant $tenant, int $branchId, ?CarbonInterface $since = null): array
    {
        $categories = MenuCategory::query()
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
            ->orderBy('sort_order')
            ->get();

        $categoryIds = MenuCategory::query()
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->pluck('id');

        return [
            'categories' => $categories->map(fn (MenuCategory $category): array => [
                'id' => $category->id,
                'branch_id' => $category->branch_id,
                'name_ar' => $category->name_ar,
                'name_en' => $category->name_en,
                'description_ar' => $category->description_ar,
                'photo_url' => $category->photoUrl(),
                'sort_order' => $category->sort_order,
                'is_visible' => $category->is_visible,
                'updated_at' => $category->updated_at?->toISOString(),
            ])->values(),
            'menu_items' => MenuItem::query()
                ->with(['parts.options', 'parts.defaultMenuItem', 'parts.ingredient'])
                ->whereIn('category_id', $categoryIds)
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->orderBy('sort_order')
                ->get()
                ->map(fn (MenuItem $item): array => [
                    'id' => $item->id,
                    'category_id' => $item->category_id,
                    'name_ar' => $item->name_ar,
                    'name_en' => $item->name_en,
                    'description_ar' => $item->description_ar,
                    'price' => (float) $item->price,
                    'photo_url' => $item->photoUrl(),
                    'is_available' => $item->is_available,
                    'preparation_time' => $item->preparation_time,
                    'sort_order' => $item->sort_order,
                    'updated_at' => $item->updated_at?->toISOString(),
                    'parts' => $this->serializeParts($item),
                ])
                ->values(),
            'packages' => $tenant->hasFeature('menu_packages')
                ? $this->packages($categoryIds, $since)
                : [],
            'offers' => $tenant->hasFeature('offers') ? $this->offers($since) : [],
            'tables' => FloorTable::query()
                ->where('branch_id', $branchId)
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->orderBy('section')
                ->orderBy('name')
                ->get([
                    'id',
                    'branch_id',
                    'name',
                    'section',
                    'capacity',
                    'status',
                    'position_x',
                    'position_y',
                    'is_active',
                    'updated_at',
                ]),
            'districts' => District::query()
                ->where('branch_id', $branchId)
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->orderBy('sort_order')
                ->get(['id', 'branch_id', 'name', 'delivery_fee', 'is_active', 'sort_order', 'updated_at']),
            'staff' => User::query()
                ->where(function ($query) use ($branchId): void {
                    $query->where('branch_id', $branchId)
                        ->orWhereIn('role', ['owner', 'manager']);
                })
                ->where('is_active', true)
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->orderBy('name')
                ->get(['id', 'name', 'role', 'branch_id', 'is_active', 'updated_at']),
            'printing' => $this->printing($branchId, $since),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchPayload(Branch $branch, Tenant $tenant): array
    {
        return [
            'id' => $branch->id,
            'name' => $branch->name,
            'name_ar' => $branch->name_ar,
            'timezone' => $branch->timezone,
            'printing_mode' => $branch->printing_mode,
            'tax_rate' => (float) ($branch->tax_rate ?? $tenant->tax_rate ?? 0),
            'tax_rate_applies_to' => $branch->tax_rate_applies_to ?? $tenant->tax_rate_applies_to ?? [],
            'service_charge_rate' => (float) ($branch->service_charge_rate ?? $tenant->service_charge_rate ?? 0),
            'service_charge_applies_to' => $branch->service_charge_applies_to ?? $tenant->service_charge_applies_to ?? [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeParts(MenuItem $item): array
    {
        return $item->parts->map(fn (MenuItemPart $part): array => [
            'id' => $part->id,
            'type' => $part->type,
            'name_ar' => $part->name_ar,
            'name_en' => $part->name_en,
            'default_menu_item_id' => $part->default_menu_item_id,
            'ingredient_id' => $part->ingredient_id,
            'quantity' => $part->quantity,
            'included_quantity' => $part->included_quantity !== null ? (float) $part->included_quantity : null,
            'unit' => $part->unit,
            'price_per_unit' => $part->price_per_unit !== null ? (float) $part->price_per_unit : null,
            'min_select' => $part->min_select,
            'max_select' => $part->max_select,
            'sort_order' => $part->sort_order,
            'options' => $part->options->map(fn ($option): array => [
                'id' => $option->id,
                'menu_item_id' => $option->menu_item_id,
                'ingredient_id' => $option->ingredient_id,
                'extra_price' => (float) $option->extra_price,
                'price_per_unit' => $option->price_per_unit !== null ? (float) $option->price_per_unit : null,
                'is_available' => $option->is_available,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, int|string>  $categoryIds
     * @return list<array<string, mixed>>
     */
    private function packages(Collection $categoryIds, ?CarbonInterface $since): array
    {
        return MenuPackage::query()
            ->with(['slots.options', 'slots.menuItem'])
            ->whereIn('category_id', $categoryIds)
            ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (MenuPackage $package): array => [
                'id' => $package->id,
                'category_id' => $package->category_id,
                'name_ar' => $package->name_ar,
                'name_en' => $package->name_en,
                'description_ar' => $package->description_ar,
                'price' => (float) $package->price,
                'photo_url' => $package->photoUrl(),
                'is_available' => $package->is_available,
                'preparation_time' => $package->preparation_time,
                'sort_order' => $package->sort_order,
                'updated_at' => $package->updated_at?->toISOString(),
                'slots' => $package->slots->map(fn ($slot): array => [
                    'id' => $slot->id,
                    'type' => $slot->type,
                    'menu_item_id' => $slot->menu_item_id,
                    'quantity' => $slot->quantity,
                    'name_ar' => $slot->name_ar,
                    'name_en' => $slot->name_en,
                    'min_select' => $slot->min_select,
                    'max_select' => $slot->max_select,
                    'sort_order' => $slot->sort_order,
                    'options' => $slot->options->map(fn ($option): array => [
                        'id' => $option->id,
                        'menu_item_id' => $option->menu_item_id,
                        'extra_price' => (float) $option->extra_price,
                        'is_available' => $option->is_available,
                    ])->values()->all(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function offers(?CarbonInterface $since): array
    {
        return Offer::query()
            ->where('is_active', true)
            ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
            ->orderBy('name_ar')
            ->get()
            ->map(fn (Offer $offer): array => $offer->toArray())
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function printing(int $branchId, ?CarbonInterface $since): array
    {
        return [
            'printers' => Printer::query()
                ->where('branch_id', $branchId)
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->orderBy('name')
                ->get(),
            'stations' => KitchenStation::query()
                ->where('branch_id', $branchId)
                ->with('printers:id')
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->orderBy('name')
                ->get()
                ->map(fn (KitchenStation $station): array => [
                    'id' => $station->id,
                    'name' => $station->name,
                    'is_active' => $station->is_active,
                    'printer_ids' => $station->printers->pluck('id')->all(),
                    'updated_at' => $station->updated_at?->toISOString(),
                ])
                ->values(),
            'routes' => PrintRoute::query()
                ->where('branch_id', $branchId)
                ->when($since !== null, fn (Builder $query): Builder => $this->changedSince($query, $since))
                ->get([
                    'id',
                    'menu_category_id',
                    'menu_item_id',
                    'printer_id',
                    'kitchen_station_id',
                    'updated_at',
                ]),
        ];
    }

    /** @return array<string, mixed> */
    private function lanHub(int $branchId): array
    {
        $hub = OfflineHub::query()->where('branch_id', $branchId)->first();
        $port = (int) config('pos-hub.port');
        $host = (string) config('pos-hub.discovery_host');

        return [
            'hub_id' => $hub?->id,
            'port' => $port,
            'discovery_host' => $host,
            'urls' => array_values(array_unique(array_filter([
                $hub?->lan_url,
                "http://{$host}:{$port}",
                "http://127.0.0.1:{$port}",
            ]))),
            'health_path' => '/api/v1/hub/health',
            'events_path' => '/api/v1/hub/events',
            'kitchen_queue_path' => '/api/v1/hub/kitchen/queue',
            'last_seen_at' => $hub?->last_seen_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function currentShift(User $user): ?array
    {
        $shift = StaffShift::query()
            ->where('user_id', $user->id)
            ->whereNull('clock_out')
            ->first();

        if (! $shift instanceof StaffShift) {
            return null;
        }

        return [
            'id' => $shift->id,
            'branch_id' => $shift->branch_id,
            'business_day_id' => $shift->business_day_id,
            'clock_in' => $shift->clock_in,
            'opening_float' => $shift->opening_float,
        ];
    }

    /** @return array<string, mixed>|null */
    private function businessDay(Request $request, int $branchId): ?array
    {
        $day = $this->businessDays->current($branchId);

        return $day !== null ? (new BusinessDayResource($day))->resolve($request) : null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function changedSince(Builder $query, CarbonInterface $since, string $column = 'updated_at'): Builder
    {
        return $query->where($column, '>=', $since->utc()->format('Y-m-d H:i:s'));
    }

    private function ordersQuery(int $branchId): Builder
    {
        return Order::query()
            ->where('branch_id', $branchId)
            ->with(['items.children', 'payment.splits', 'adjustments'])
            ->orderBy('created_at');
    }
}
